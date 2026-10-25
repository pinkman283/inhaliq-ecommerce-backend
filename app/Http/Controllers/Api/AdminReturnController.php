<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\OrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminReturnController extends Controller
{
    protected OrderReturnService $returnService;

    public function __construct(OrderReturnService $returnService)
    {
        $this->returnService = $returnService;
    }

    /**
     * List returns and RTOs with tabs, filtering, and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'returns.view', 'returns.manage');

        $query = OrderReturn::with([
            'order:id,order_number,total_amount,payment_status,order_status,payment_method',
            'customer:id,name,phone,email',
            'shipment:id,provider,consignment_id,tracking_code,status',
            'items.product:id,name,sku',
            'items.product.primaryImage',
            'items.variant',
        ]);

        $tab = $request->query('tab', 'all');

        // Apply tab filtering
        switch ($tab) {
            case 'rto':
                $query->whereIn('return_type', ['rto', 'failed_delivery']);
                break;
            case 'customer_return':
                $query->where('return_type', 'customer_return');
                break;
            case 'awaiting_inspection':
                $query->where('status', 'received')->where('inspection_status', 'pending');
                break;
            case 'refunded':
                $query->whereIn('refund_status', ['processed', 'store_credit_issued']);
                break;
            case 'all':
            default:
                break;
        }

        // Search query
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'LIKE', "%{$search}%")
                    ->orWhere('courier_tracking_code', 'LIKE', "%{$search}%")
                    ->orWhereHas('order', function ($oq) use ($search) {
                        $oq->where('order_number', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Return type filter
        if ($request->filled('return_type')) {
            $query->where('return_type', $request->return_type);
        }

        // Date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = max(10, min(100, (int) $request->query('per_page', 20)));
        $returns = $query->orderBy('id', 'desc')->paginate($perPage);

        // Tab counts & Summary Stats
        $stats = [
            'all_count' => OrderReturn::count(),
            'rto_count' => OrderReturn::whereIn('return_type', ['rto', 'failed_delivery'])->count(),
            'customer_returns_count' => OrderReturn::where('return_type', 'customer_return')->count(),
            'awaiting_inspection_count' => OrderReturn::where('status', 'received')->where('inspection_status', 'pending')->count(),
            'refunded_count' => OrderReturn::whereIn('refund_status', ['processed', 'store_credit_issued'])->count(),
            'total_refunded_amount' => (float) OrderReturn::whereIn('refund_status', ['processed', 'store_credit_issued'])->sum('refund_amount'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $returns->items(),
            'meta' => [
                'current_page' => $returns->currentPage(),
                'last_page' => $returns->lastPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Show detailed return record.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->checkPermission($request, 'returns.view', 'returns.manage');

        $query = OrderReturn::with([
            'order.items.product',
            'order.latestShipment',
            'customer',
            'shipment',
            'createdByUser:id,name,email',
            'inspectedByUser:id,name,email',
            'items.product.primaryImage',
            'items.variant',
            'items.orderItem',
        ]);

        if (is_numeric($id)) {
            $orderReturn = $query->find((int) $id);
        } else {
            $orderReturn = $query->where('return_number', $id)->first();
        }

        // Fallback by order_number or tracking_code
        if (!$orderReturn) {
            $orderReturn = $query->whereHas('order', function ($q) use ($id) {
                $q->where('order_number', $id);
            })->orWhere('courier_tracking_code', $id)->first();
        }

        if (!$orderReturn) {
            return response()->json([
                'status' => 'error',
                'message' => "Return record '{$id}' not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $orderReturn,
        ]);
    }

    /**
     * Create a new Return or RTO manually from admin.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage');

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'return_type' => 'required|in:rto,customer_return,failed_delivery,partial_rejection',
            'return_reason' => 'nullable|string|max:255',
            'amount_collected_courier' => 'nullable|numeric|min:0',
            'courier_delivery_fee' => 'nullable|numeric|min:0',
            'courier_rto_fee' => 'nullable|numeric|min:0',
            'courier_tracking_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.order_item_id' => 'nullable|exists:order_items,id',
            'items.*.quantity_returned' => 'required_with:items|integer|min:1',
            'items.*.return_reason' => 'nullable|string',
            'items.*.condition' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::findOrFail($request->order_id);
        $orderReturn = $this->returnService->createReturn($order, $request->all(), $request->user());

        AuditLog::log(
            $request->user()?->id,
            'order.return_created',
            'OrderReturn',
            $orderReturn->id,
            "Created return #{$orderReturn->return_number} for Order #{$order->order_number} ({$orderReturn->return_type})",
            null,
            $orderReturn->toArray()
        );

        return response()->json([
            'status' => 'success',
            'message' => "Return #{$orderReturn->return_number} created successfully.",
            'data' => $orderReturn,
        ], 201);
    }

    /**
     * Mark return parcel physically received at warehouse.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage');

        $orderReturn = OrderReturn::findOrFail($id);

        $orderReturn = $this->returnService->receiveReturn($orderReturn, $request->all(), $request->user());

        AuditLog::log(
            $request->user()?->id,
            'order.return_received',
            'OrderReturn',
            $orderReturn->id,
            "Parcel physically received for Return #{$orderReturn->return_number}",
            null,
            ['status' => 'received', 'received_at' => $orderReturn->received_at]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Return parcel #{$orderReturn->return_number} marked as received at warehouse.",
            'data' => $orderReturn,
        ]);
    }

    /**
     * Submit QC Inspection results and inventory disposition.
     */
    public function qc(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage');

        $orderReturn = OrderReturn::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:order_return_items,id',
            'items.*.disposition' => 'required|in:restock_sellable,quarantine_damaged,write_off_loss,return_to_customer',
            'items.*.restocked_quantity' => 'nullable|integer|min:0',
            'items.*.damaged_quantity' => 'nullable|integer|min:0',
            'items.*.writeoff_quantity' => 'nullable|integer|min:0',
            'items.*.refund_unit_price' => 'nullable|numeric|min:0',
            'items.*.condition' => 'nullable|string',
            'items.*.qc_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderReturn = $this->returnService->inspectAndDispose($orderReturn, $request->items, $request->user());

        AuditLog::log(
            $request->user()?->id,
            'order.return_qc_completed',
            'OrderReturn',
            $orderReturn->id,
            "QC inspection completed for Return #{$orderReturn->return_number}: {$orderReturn->inspection_status}",
            null,
            ['inspection_status' => $orderReturn->inspection_status, 'refund_amount' => $orderReturn->refund_amount]
        );

        return response()->json([
            'status' => 'success',
            'message' => "QC inspection recorded. Restocked sellable inventory updated with FIFO layer.",
            'data' => $orderReturn,
        ]);
    }

    /**
     * Process refund for the return.
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage', 'finance.manage');

        $orderReturn = OrderReturn::with('order')->findOrFail($id);

        if (in_array($orderReturn->refund_status, ['processed', 'store_credit_issued'])) {
            return response()->json([
                'status' => 'error',
                'message' => "Return #{$orderReturn->return_number} has already been refunded.",
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'refund_amount' => 'required|numeric|min:0',
            'refund_method' => 'required|in:cash,bank_transfer,bkash,nagad,rocket,store_credit,none',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = $orderReturn->order;
        $remainingRefundable = max(0.00, round((float) $order->total_amount - (float) ($order->amount_refunded ?? 0.00), 2));
        $requestedAmount = round((float) $request->input('refund_amount', $orderReturn->refund_amount), 2);

        if ($requestedAmount > $remainingRefundable) {
            return response()->json([
                'status' => 'error',
                'message' => "Refund amount (৳{$requestedAmount}) exceeds the order's remaining refundable balance of ৳{$remainingRefundable}.",
            ], 422);
        }

        $orderReturn = $this->returnService->processRefund($orderReturn, $request->all(), $request->user());

        AuditLog::log(
            $request->user(),
            'order.return_refund_processed',
            'OrderReturn',
            $orderReturn->id,
            "Processed refund of ৳{$orderReturn->refund_amount} via {$orderReturn->refund_method} for Return #{$orderReturn->return_number}",
            null,
            ['refund_amount' => $orderReturn->refund_amount, 'refund_method' => $orderReturn->refund_method]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Refund of ৳{$orderReturn->refund_amount} processed and posted to ledger.",
            'data' => $orderReturn,
        ]);
    }

    /**
     * Delete a single return record.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage');

        $orderReturn = OrderReturn::with('items')->findOrFail($id);
        $returnNumber = $orderReturn->return_number;
        $oldValues = $orderReturn->toArray();

        DB::transaction(function () use ($orderReturn, $request, $id, $returnNumber, $oldValues) {
            $orderReturn->items()->delete();
            $orderReturn->delete();

            AuditLog::log(
                $request->user(),
                'order.return_deleted',
                'OrderReturn',
                $id,
                "Deleted return #{$returnNumber}",
                $oldValues,
                null
            );
        });

        return response()->json([
            'status' => 'success',
            'message' => "Return #{$returnNumber} deleted successfully.",
        ]);
    }

    /**
     * Bulk delete return records.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'returns.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $count = 0;

        DB::transaction(function () use ($validated, $request, &$count) {
            $returns = OrderReturn::with('items')->whereIn('id', $validated['ids'])->get();

            foreach ($returns as $orderReturn) {
                $returnNumber = $orderReturn->return_number;
                $oldValues = $orderReturn->toArray();

                $orderReturn->items()->delete();
                $orderReturn->delete();
                $count++;

                AuditLog::log(
                    $request->user(),
                    'order.return_deleted',
                    'OrderReturn',
                    $orderReturn->id,
                    "Bulk deleted return #{$returnNumber}",
                    $oldValues,
                    null
                );
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Successfully deleted {$count} return(s).",
            'deleted_count' => $count,
        ]);
    }
}
