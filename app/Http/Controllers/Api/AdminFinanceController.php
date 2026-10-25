<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CsvSanitizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminFinanceController extends Controller
{
    private function getDateRange(Request $request): array
    {
        $period = $request->input('period', 'this_month');
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case 'this_week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                break;
            case 'last_week':
                $start = $now->copy()->subWeek()->startOfWeek();
                $end = $now->copy()->subWeek()->endOfWeek();
                break;
            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $now->copy()->subMonth()->endOfMonth();
                break;
            case 'this_quarter':
                $start = $now->copy()->firstOfQuarter();
                $end = $now->copy()->lastOfQuarter();
                break;
            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'custom':
                $start = $request->filled('date_from') ? Carbon::parse($request->input('date_from'))->startOfDay() : $now->copy()->startOfMonth();
                $end = $request->filled('date_to') ? Carbon::parse($request->input('date_to'))->endOfDay() : $now->copy()->endOfDay();
                break;
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
        }

        return [$start, $end];
    }

    public function summary(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'finance.reports_view');

        [$startDate, $endDate] = $this->getDateRange($request);

        // 1. Orders within period
        $ordersQuery = Order::whereBetween('created_at', [$startDate, $endDate]);

        $allOrders = (clone $ordersQuery)->get();
        $validOrders = $allOrders->whereNotIn('order_status', ['cancelled']);
        $refundedOrders = $allOrders->where('order_status', 'refunded');

        // Gross Sales before deductions
        $grossSales = 0.00;
        foreach ($validOrders as $order) {
            $grossSales += (float) ($order->subtotal + $order->shipping_amount + $order->tax_amount);
        }

        $discounts = (float) $validOrders->sum('discount_amount');
        $refunds = (float) $refundedOrders->sum('total_amount');
        $netSales = max(0, $grossSales - $discounts - $refunds);

        // COGS from completed/valid orders
        $nonRefundedOrders = $validOrders->where('order_status', '!=', 'refunded');
        $totalCogs = 0.00;
        foreach ($nonRefundedOrders as $order) {
            $orderCogs = (float) $order->cogs_amount;
            // If cogs_amount was 0, compute fallback from items
            if ($orderCogs <= 0 && $order->items->count() > 0) {
                $orderCogs = (float) $order->items->sum('cogs_total');
                if ($orderCogs <= 0) {
                    $orderCogs = (float) ($order->subtotal * 0.5); // Fallback estimate
                }
            }
            $totalCogs += $orderCogs;
        }

        $grossProfit = $netSales - $totalCogs;
        $grossMarginPct = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.00;

        // 2. Operating Expenses within period
        $operatingExpenses = (float) Expense::whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        $operatingProfit = $grossProfit - $operatingExpenses;
        $netMarginPct = $netSales > 0 ? round(($operatingProfit / $netSales) * 100, 2) : 0.00;

        $ordersCount = $validOrders->count();
        $avgOrderValue = $ordersCount > 0 ? round($netSales / $ordersCount, 2) : 0.00;

        // Channel Breakdown (Online vs POS)
        $onlineOrders = $validOrders->where('order_source', 'online');
        $posOrders = $validOrders->where('order_source', 'pos');

        $onlineSales = (float) $onlineOrders->sum('total_amount');
        $posSales = (float) $posOrders->sum('total_amount');

        // Expense by Category Breakdown
        $expenseCategories = Expense::with('category')
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->select('expense_category_id', DB::raw('SUM(amount) as total_spent'), DB::raw('COUNT(*) as count'))
            ->groupBy('expense_category_id')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->expense_category_id,
                    'category_name' => $item->category?->name ?? 'General',
                    'total_spent' => round((float) $item->total_spent, 2),
                    'count' => $item->count,
                ];
            });

        return response()->json([
            'period' => [
                'from' => $startDate->toDateTimeString(),
                'to' => $endDate->toDateTimeString(),
            ],
            'metrics' => [
                'gross_sales' => round($grossSales, 2),
                'discounts' => round($discounts, 2),
                'refunds' => round($refunds, 2),
                'net_sales' => round($netSales, 2),
                'cogs' => round($totalCogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin_percentage' => $grossMarginPct,
                'operating_expenses' => round($operatingExpenses, 2),
                'operating_profit' => round($operatingProfit, 2),
                'net_margin_percentage' => $netMarginPct,
                'total_orders' => $ordersCount,
                'avg_order_value' => $avgOrderValue,
            ],
            'channels' => [
                'online' => [
                    'sales' => round($onlineSales, 2),
                    'orders_count' => $onlineOrders->count(),
                ],
                'pos' => [
                    'sales' => round($posSales, 2),
                    'orders_count' => $posOrders->count(),
                ],
            ],
            'expense_categories' => $expenseCategories,
        ]);
    }

    public function productProfitability(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'finance.reports_view');

        [$startDate, $endDate] = $this->getDateRange($request);

        $orderIds = Order::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('order_status', ['cancelled'])
            ->pluck('id');

        $items = OrderItem::whereIn('order_id', $orderIds)->get();

        $grouped = $items->groupBy('product_id')->map(function ($orderItems, $productId) {
            $product = Product::with(['category', 'primaryImage', 'activeCostLayers'])->find($productId);
            $unitsSold = $orderItems->sum('quantity');
            $grossRevenue = (float) $orderItems->sum('total_price');
            $discounts = (float) $orderItems->sum('discount_amount');
            $netRevenue = max(0, $grossRevenue - $discounts);

            $cogs = (float) $orderItems->sum('cogs_total');
            if ($cogs <= 0 && $product) {
                $cogs = (float) ($netRevenue * 0.5);
            }

            $grossProfit = $netRevenue - $cogs;
            $grossMargin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0.00;

            $stockOnHand = $product ? $product->stock_quantity : 0;
            $inventoryValue = 0.00;
            if ($product) {
                foreach ($product->activeCostLayers as $layer) {
                    $inventoryValue += ($layer->remaining_quantity * (float) $layer->unit_cost);
                }
                if ($inventoryValue <= 0 && $stockOnHand > 0) {
                    $inventoryValue = $stockOnHand * ($product->price * 0.5);
                }
            }

            return [
                'product_id' => $productId,
                'name' => $product?->name ?? ($orderItems->first()?->product_name ?? 'Unknown Product'),
                'sku' => $product?->sku ?? ($orderItems->first()?->product_sku ?? 'N/A'),
                'category' => $product?->category?->name ?? 'General',
                'image' => $product?->primaryImage?->image_url,
                'units_sold' => $unitsSold,
                'net_revenue' => round($netRevenue, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin_percentage' => $grossMargin,
                'stock_on_hand' => $stockOnHand,
                'inventory_value' => round($inventoryValue, 2),
            ];
        })->values()->sortByDesc('gross_profit')->values();

        return response()->json($grouped);
    }

    public function vendorAnalytics(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'finance.reports_view');

        $vendors = Vendor::with([
            'purchaseOrders.items',
            'vendorProducts.product',
        ])->get()->map(function ($vendor) {
            $validPOs = $vendor->purchaseOrders->whereNotIn('status', ['cancelled']);
            $totalSpend = (float) $validPOs->sum('total_amount');
            $poCount = $validPOs->count();

            $totalUnitsPurchased = 0;
            foreach ($validPOs as $po) {
                $totalUnitsPurchased += $po->items->sum('quantity_ordered');
            }

            $avgUnitCost = $totalUnitsPurchased > 0 ? round($totalSpend / $totalUnitsPurchased, 2) : 0.00;

            return [
                'id' => $vendor->id,
                'name' => $vendor->company_name,
                'vendor_code' => $vendor->vendor_code,
                'status' => $vendor->status,
                'total_spend' => round($totalSpend, 2),
                'purchase_orders_count' => $poCount,
                'units_purchased' => $totalUnitsPurchased,
                'avg_unit_cost' => $avgUnitCost,
                'products_supplied_count' => $vendor->vendorProducts->count(),
            ];
        })->sortByDesc('total_spend')->values();

        return response()->json($vendors);
    }

    public function drilldown(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'finance.drilldown');

        [$startDate, $endDate] = $this->getDateRange($request);
        $metric = $request->input('metric', 'sales');

        switch ($metric) {
            case 'cogs':
            case 'sales':
                $orders = Order::with(['items.costLayers', 'items.product', 'user', 'cashier'])
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->latest()
                    ->paginate(25);
                return response()->json(['type' => 'orders', 'data' => $orders]);

            case 'expenses':
                $expenses = Expense::with(['category', 'payeeVendor', 'createdByUser'])
                    ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->latest('expense_date')
                    ->paginate(25);
                return response()->json(['type' => 'expenses', 'data' => $expenses]);

            default:
                return response()->json(['type' => 'unknown', 'data' => []]);
        }
    }

    public function export(Request $request): StreamedResponse
    {
        $this->checkPermission($request, 'finance.reports_view');

        [$startDate, $endDate] = $this->getDateRange($request);
        $type = $request->input('type', 'pnl');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"aether-{$type}-report-" . date('Ymd') . ".csv\"",
        ];

        return response()->stream(function () use ($type, $startDate, $endDate) {
            $handle = fopen('php://output', 'w');

            if ($type === 'pnl') {
                fputcsv($handle, ['Metric', 'Amount (USD)', 'Notes']);
                $validOrders = Order::whereBetween('created_at', [$startDate, $endDate])
                    ->whereNotIn('order_status', ['cancelled'])
                    ->get();
                $refunds = Order::whereBetween('created_at', [$startDate, $endDate])
                    ->where('order_status', 'refunded')
                    ->sum('total_amount');
                
                $grossSales = $validOrders->sum('total_amount') + $validOrders->sum('discount_amount');
                $discounts = $validOrders->sum('discount_amount');
                $netSales = max(0, $grossSales - $discounts - $refunds);
                $cogs = $validOrders->where('order_status', '!=', 'refunded')->sum('cogs_amount');
                $grossProfit = $netSales - $cogs;
                $expenses = Expense::whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->where('status', '!=', 'cancelled')
                    ->sum('amount');
                $netProfit = $grossProfit - $expenses;

                fputcsv($handle, ['Gross Sales', number_format($grossSales, 2), 'Total sales before deductions']);
                fputcsv($handle, ['Discounts Applied', '-' . number_format($discounts, 2), 'Promotional & coupon vouchers']);
                fputcsv($handle, ['Refunds & Returns', '-' . number_format($refunds, 2), 'Refunded order value']);
                fputcsv($handle, ['Net Sales Revenue', number_format($netSales, 2), 'Gross Sales - Discounts - Refunds']);
                fputcsv($handle, ['Cost of Goods Sold (COGS)', '-' . number_format($cogs, 2), 'FIFO cost of inventory fulfilled']);
                fputcsv($handle, ['Gross Profit', number_format($grossProfit, 2), 'Net Sales - COGS']);
                fputcsv($handle, ['Operating Expenses', '-' . number_format($expenses, 2), 'Operating period expenses (rent, marketing, salaries)']);
                fputcsv($handle, ['Net Operating Profit', number_format($netProfit, 2), 'Gross Profit - Operating Expenses']);
            } elseif ($type === 'sales') {
                fputcsv($handle, ['Order Number', 'Date', 'Source', 'Customer', 'Subtotal', 'Tax', 'Discount', 'Total', 'COGS', 'Gross Profit', 'Status']);
                $orders = Order::whereBetween('created_at', [$startDate, $endDate])->get();
                foreach ($orders as $o) {
                    fputcsv($handle, CsvSanitizer::sanitizeRow([
                        $o->order_number,
                        $o->created_at->format('Y-m-d H:i:s'),
                        strtoupper($o->order_source),
                        $o->customer_name,
                        number_format($o->subtotal, 2),
                        number_format($o->tax_amount, 2),
                        number_format($o->discount_amount, 2),
                        number_format($o->total_amount, 2),
                        number_format($o->cogs_amount, 2),
                        number_format($o->gross_profit, 2),
                        $o->order_status,
                    ]));
                }
            }

            fclose($handle);
        }, 200, $headers);
    }
}
