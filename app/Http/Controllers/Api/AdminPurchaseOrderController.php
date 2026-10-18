<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.view');

        $query = PurchaseOrder::with(['vendor', 'createdByUser', 'approvedByUser'])
            ->withCount('items')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%")
                         ->orWhere('company_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->input('date_to'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $purchaseOrders = $query->paginate($perPage);

        // Procurement summary stats
        $totalPOs = PurchaseOrder::count();
        $draftCount = PurchaseOrder::where('status', 'draft')->count();
        $submittedCount = PurchaseOrder::where('status', 'submitted')->count();
        $approvedCount = PurchaseOrder::where('status', 'approved')->count();
        $partiallyReceivedCount = PurchaseOrder::where('status', 'partially_received')->count();
        $receivedCount = PurchaseOrder::where('status', 'received')->count();
        $totalSpend = (float) PurchaseOrder::whereIn('status', ['approved', 'partially_received', 'received'])->sum('total_amount');

        return response()->json([
            'purchase_orders' => $purchaseOrders,
            'stats' => [
                'total_pos' => $totalPOs,
                'draft_count' => $draftCount,
                'submitted_count' => $submittedCount,
                'approved_count' => $approvedCount,
                'partially_received_count' => $partiallyReceivedCount,
                'received_count' => $receivedCount,
                'total_procurement_spend' => $totalSpend,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.view');

        $po = PurchaseOrder::with([
            'vendor',
            'createdByUser',
            'approvedByUser',
            'items.product.primaryImage',
            'items.variant',
            'goodsReceipts.items',
            'goodsReceipts.receivedByUser',
        ])->findOrFail($id);

        return response()->json($po);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.create');

        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'shipping_cost' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'other_costs' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.quantity_ordered' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $subtotal = 0.00;
            $itemsData = [];

            foreach ($validated['items'] as $itemInput) {
                $product = Product::findOrFail($itemInput['product_id']);
                $variant = !empty($itemInput['variant_id']) ? ProductVariant::find($itemInput['variant_id']) : null;

                if ($variant && (int) $variant->product_id !== (int) $product->id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["Selected variant does not belong to product '{$product->name}'."],
                    ]);
                }

                $unitCost = (float) $itemInput['unit_cost'];
                $qty = (int) $itemInput['quantity_ordered'];
                $itemSubtotal = $unitCost * $qty;
                $subtotal += $itemSubtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'product_name' => $product->name . ($variant ? " ({$variant->name})" : ""),
                    'sku' => $variant?->sku ?: $product->sku,
                    'unit_cost' => $unitCost,
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'quantity_damaged' => 0,
                    'quantity_rejected' => 0,
                    'subtotal' => $itemSubtotal,
                ];
            }

            $shipping = (float) ($validated['shipping_cost'] ?? 0);
            $tax = (float) ($validated['tax_amount'] ?? 0);
            $other = (float) ($validated['other_costs'] ?? 0);
            $totalAmount = $subtotal + $shipping + $tax + $other;

            $poNumber = 'PO-' . date('Y') . '-' . strtoupper(Str::random(6));

            $po = PurchaseOrder::create([
                'po_number' => $poNumber,
                'vendor_id' => $validated['vendor_id'],
                'status' => 'draft',
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'subtotal' => $subtotal,
                'shipping_cost' => $shipping,
                'tax_amount' => $tax,
                'other_costs' => $other,
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]);

            foreach ($itemsData as $itemData) {
                $po->items()->create($itemData);
            }

            AuditLog::log(
                $request->user(),
                'purchase_order.created',
                'PurchaseOrder',
                $po->id,
                "Drafted Purchase Order {$po->po_number} for vendor #{$po->vendor_id} (Total: \${$po->total_amount}).",
                null,
                $po->toArray()
            );

            return response()->json([
                'message' => "Purchase order {$po->po_number} created in draft status.",
                'purchase_order' => $po->load(['vendor', 'items']),
            ], 201);
        });
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.create');

        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') {
            return response()->json(['message' => "Only draft POs can be submitted. Current status: {$po->status}"], 422);
        }

        $po->update(['status' => 'submitted']);

        AuditLog::log(
            $request->user(),
            'purchase_order.submitted',
            'PurchaseOrder',
            $po->id,
            "Submitted Purchase Order {$po->po_number} for administrative approval."
        );

        return response()->json([
            'message' => "Purchase Order {$po->po_number} submitted successfully.",
            'purchase_order' => $po->fresh(['vendor', 'items']),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.approve');

        $po = PurchaseOrder::findOrFail($id);
        if (!in_array($po->status, ['draft', 'submitted'])) {
            return response()->json(['message' => "PO cannot be approved in its current status ({$po->status})."], 422);
        }

        $po->update([
            'status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        AuditLog::log(
            $request->user(),
            'purchase_order.approved',
            'PurchaseOrder',
            $po->id,
            "Approved Purchase Order {$po->po_number} for fulfillment (Approved by {$request->user()->name})."
        );

        return response()->json([
            'message' => "Purchase Order {$po->po_number} approved.",
            'purchase_order' => $po->fresh(['vendor', 'items', 'approvedByUser']),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'purchase_orders.create');

        $po = PurchaseOrder::findOrFail($id);
        if (in_array($po->status, ['received', 'cancelled'])) {
            return response()->json(['message' => "Cannot cancel a PO that is already {$po->status}."], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $po->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $validated['reason'],
        ]);

        AuditLog::log(
            $request->user(),
            'purchase_order.cancelled',
            'PurchaseOrder',
            $po->id,
            "Cancelled Purchase Order {$po->po_number}. Reason: {$validated['reason']}"
        );

        return response()->json([
            'message' => "Purchase Order {$po->po_number} cancelled.",
            'purchase_order' => $po->fresh(['vendor', 'items']),
        ]);
    }
}
