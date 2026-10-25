<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\CsvSanitizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSalesController extends Controller
{
    /**
     * Get paginated sales history with comprehensive filtering and KPI aggregates.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage', 'finance.reports_view');

        $query = Order::with(['items.product', 'items.variant', 'user', 'cashierUser', 'posRegisterSession.posRegister'])
            ->latest('created_at');

        // Filter by Sales Channel / Source
        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('order_source', $request->input('source'));
        }

        // Filter by Search Query
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('payment_transaction_id', 'like', "%{$search}%");
            });
        }

        // Filter by Payment Status
        if ($request->filled('payment_status') && $request->input('payment_status') !== 'all') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        // Filter by Payment Method
        if ($request->filled('payment_method') && $request->input('payment_method') !== 'all') {
            $query->where('payment_method', $request->input('payment_method'));
        }

        // Filter by Date Range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Compute Aggregates on the Filtered Query Base
        $statsQuery = clone $query;
        $totalSales = (float) $statsQuery->where('payment_status', '!=', 'failed')->sum('total_amount');
        $totalTransactions = $statsQuery->count();
        $avgTicket = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0.0;

        $posSales = (float) (clone $query)->where('order_source', 'pos')->where('payment_status', '!=', 'failed')->sum('total_amount');
        $onlineSales = (float) (clone $query)->where('order_source', 'online')->where('payment_status', '!=', 'failed')->sum('total_amount');
        $totalTax = (float) (clone $query)->where('payment_status', '!=', 'failed')->sum('tax_amount');
        $totalDiscount = (float) (clone $query)->where('payment_status', '!=', 'failed')->sum('discount_amount');

        $perPage = (int) $request->input('per_page', 20);
        $sales = $query->paginate($perPage);

        return response()->json([
            'sales' => $sales,
            'summary' => [
                'total_sales' => round($totalSales, 2),
                'total_transactions' => $totalTransactions,
                'average_invoice_value' => round($avgTicket, 2),
                'pos_sales' => round($posSales, 2),
                'online_sales' => round($onlineSales, 2),
                'total_tax_collected' => round($totalTax, 2),
                'total_discount_given' => round($totalDiscount, 2),
            ]
        ]);
    }

    /**
     * Get detailed invoice data for an order.
     */
    public function invoice(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage', 'finance.reports_view');

        $order = Order::with([
            'items.product',
            'items.variant',
            'user',
            'cashierUser',
            'posRegisterSession.posRegister',
            'latestShipment'
        ])->findOrFail($id);

        $brandName = Setting::get('store_brand_name', Setting::get('store_name', 'Store'));
        $brandLogo = Setting::get('store_brand_logo', '');
        $brandTagline = Setting::get('store_brand_tagline', '');
        $contactRaw = Setting::get('business_contact', []);
        $contact = is_array($contactRaw) ? $contactRaw : (json_decode((string) $contactRaw, true) ?: []);

        $companyAddress = $contact['warehouse_address'] ?? Setting::get('store_address', '');
        $companyPhone = $contact['hotline'] ?? Setting::get('store_phone', '');
        $companyEmail = $contact['support_email'] ?? Setting::get('store_email', '');
        $companyWebsite = Setting::get('store_website', config('app.url', ''));
        $companyTax = Setting::get('store_tax_number', Setting::get('tax_number', ''));

        $trackingCode = $order->tracking_code 
            ?: ($order->latestShipment?->tracking_code 
                ?: ($order->latestShipment?->consignment_id ?: $order->order_number));
        $carrier = $order->carrier ?: ($order->latestShipment?->provider ?? null);

        $invoiceData = [
            'invoice_number' => 'INV-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'order_number' => $order->order_number,
            'order_source' => $order->order_source ?? 'online',
            'issue_date' => $order->created_at->toFormattedDateString(),
            'issue_timestamp' => $order->created_at->format('Y-m-d H:i:s'),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'order_status' => $order->order_status,
            'carrier' => $carrier,
            'tracking_code' => $trackingCode,
            'tracking_id' => $trackingCode,
            'shipping_method' => $order->shipping_method,
            'company' => [
                'name' => $brandName ?: ($contact['legal_name'] ?? 'Store'),
                'logo' => $brandLogo ?: null,
                'tagline' => $brandTagline,
                'address' => $companyAddress,
                'tax_number' => $companyTax,
                'phone' => $companyPhone,
                'email' => $companyEmail,
                'website' => $companyWebsite,
            ],
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
            ],
            'terminal' => [
                'register_name' => $order->posRegisterSession?->posRegister?->name ?? 'Online Checkout Gateway',
                'cashier_name' => $order->cashierUser?->name ?? 'Automated Web Store',
                'session_id' => $order->pos_register_session_id,
            ],
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'sku' => $item->product_sku ?? $item->product?->sku ?? 'N/A',
                    'name' => $item->product_name ?? $item->product?->name ?? 'Hardware Item',
                    'variant' => $item->variant_name ?? $item->variant?->name ?? null,
                    'variant_attributes' => $item->variant_attributes ?? $item->variant?->variant_attributes ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'total_price' => (float) $item->total_price,
                ];
            }),
            'financials' => [
                'subtotal' => (float) $order->subtotal,
                'discount_amount' => (float) $order->discount_amount,
                'tax_amount' => (float) $order->tax_amount,
                'shipping_amount' => (float) $order->shipping_amount,
                'total_amount' => (float) $order->total_amount,
                'cash_received' => (float) $order->cash_received,
                'change_returned' => (float) $order->change_returned,
                'payment_transaction_id' => $order->payment_transaction_id,
            ],
            'notes' => $order->notes,
        ];

        return response()->json($invoiceData);
    }

    /**
     * Stream CSV export of sales records.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage', 'finance.reports_view');

        $query = Order::with(['items', 'cashierUser', 'posRegisterSession.posRegister'])->latest('created_at');

        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('order_source', $request->input('source'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $filename = 'sales_ledger_' . now()->format('Y_m_d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Invoice / Order #',
                'Date',
                'Source Channel',
                'Customer Name',
                'Customer Email',
                'Customer Phone',
                'Cashier / Terminal',
                'Payment Method',
                'Payment Status',
                'Subtotal',
                'Discount',
                'Tax',
                'Shipping',
                'Total Amount',
                'FIFO COGS',
                'Gross Profit',
            ]);

            $query->chunk(100, function ($orders) use ($handle) {
                foreach ($orders as $o) {
                    fputcsv($handle, CsvSanitizer::sanitizeRow([
                        $o->order_number,
                        $o->created_at->format('Y-m-d H:i:s'),
                        strtoupper($o->order_source ?? 'online'),
                        $o->customer_name,
                        $o->customer_email,
                        $o->customer_phone,
                        $o->cashierUser?->name ?? 'Storefront Gateway',
                        strtoupper(str_replace('_', ' ', $o->payment_method ?? 'cash')),
                        strtoupper($o->payment_status ?? 'paid'),
                        $o->subtotal,
                        $o->discount_amount,
                        $o->tax_amount,
                        $o->shipping_amount,
                        $o->total_amount,
                        $o->cogs_amount ?? 0.00,
                        $o->gross_profit ?? 0.00,
                    ]));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
