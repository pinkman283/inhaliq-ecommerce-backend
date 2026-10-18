<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vendor;
use App\Services\InventoryCostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminInventoryValuationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'inventory.valuation');

        $query = Product::with(['category', 'primaryImage', 'activeCostLayers', 'variants'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'all') {
            $query->where('category_id', $request->input('category_id'));
        }

        $products = $query->get()->map(function ($product) {
            $activeLayers = $product->activeCostLayers;
            $realFifoValuation = 0.00;
            $layerUnits = 0;

            foreach ($activeLayers as $layer) {
                $realFifoValuation += ($layer->remaining_quantity * (float) $layer->unit_cost);
                $layerUnits += $layer->remaining_quantity;
            }

            $costStatus = 'costed';
            $isEstimated = false;
            $avgUnitCost = 0.00;
            $totalValuation = 0.00;

            if ($product->stock_quantity <= 0) {
                $costStatus = 'depleted';
                $avgUnitCost = 0.00;
                $totalValuation = 0.00;
            } elseif ($layerUnits > 0) {
                $costStatus = 'costed';
                $avgUnitCost = round($realFifoValuation / $layerUnits, 2);
                $totalValuation = $realFifoValuation;
            } elseif ($product->cost_price !== null && (float)$product->cost_price > 0) {
                $costStatus = 'opening_cost_pending_layer';
                $isEstimated = true;
                $avgUnitCost = (float)$product->cost_price;
                $totalValuation = $product->stock_quantity * $avgUnitCost;
            } else {
                $costStatus = 'cost_not_established';
                $isEstimated = true;
                $avgUnitCost = null;
                $totalValuation = 0.00;
            }

            if ($product->variants && $product->variants->isNotEmpty()) {
                $potentialRetailValue = (float) $product->variants->sum(function ($v) {
                    return (int) $v->stock_quantity * (float) $v->effective_price;
                });
            } else {
                $potentialRetailValue = (int) $product->stock_quantity * (float) $product->price;
            }
            $potentialGrossMargin = $potentialRetailValue - $totalValuation;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name ?? 'Uncategorized',
                'category_id' => $product->category_id,
                'stock_quantity' => $product->stock_quantity,
                'retail_price' => (float) $product->price,
                'cost_price' => $product->cost_price ? (float)$product->cost_price : null,
                'average_unit_cost' => $avgUnitCost,
                'cost_status' => $costStatus,
                'is_estimated' => $isEstimated,
                'real_fifo_units' => $layerUnits,
                'real_fifo_valuation' => round($realFifoValuation, 2),
                'total_inventory_cost' => round($totalValuation, 2),
                'potential_retail_value' => round($potentialRetailValue, 2),
                'potential_gross_margin' => round($potentialGrossMargin, 2),
                'cost_layers_count' => $activeLayers->count(),
                'active_layers' => $activeLayers,
                'image' => $product->primaryImage?->image_url,
            ];
        });

        // Compute overall inventory asset metrics
        $totalUnits = $products->sum('stock_quantity');
        $costedUnits = $products->sum('real_fifo_units');
        $uncostedUnits = max(0, $totalUnits - $costedUnits);
        $totalAssetCost = $products->sum('total_inventory_cost');
        $realFifoAssetCost = $products->sum('real_fifo_valuation');
        $totalRetailValue = $products->sum('potential_retail_value');
        $lowStockProducts = $products->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10)->count();
        $outOfStockProducts = $products->where('stock_quantity', '<=', 0)->count();

        // Category valuation breakdown
        $categoryBreakdown = Category::with('products.activeCostLayers')->get()->map(function ($cat) {
            $catUnits = 0;
            $catCost = 0.00;
            foreach ($cat->products as $p) {
                $catUnits += $p->stock_quantity;
                foreach ($p->activeCostLayers as $layer) {
                    $catCost += ($layer->remaining_quantity * (float) $layer->unit_cost);
                }
            }
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'units' => $catUnits,
                'valuation' => round($catCost, 2),
            ];
        })->filter(fn($c) => $c['units'] > 0)->values();

        return response()->json([
            'summary' => [
                'total_units' => $totalUnits,
                'costed_units' => $costedUnits,
                'uncosted_units' => $uncostedUnits,
                'real_fifo_valuation' => round($realFifoAssetCost, 2),
                'total_asset_valuation' => round($totalAssetCost, 2),
                'total_potential_retail_value' => round($totalRetailValue, 2),
                'low_stock_count' => $lowStockProducts,
                'out_of_stock_count' => $outOfStockProducts,
            ],
            'products' => $products,
            'category_breakdown' => $categoryBreakdown,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'inventory.adjust');

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'adjustment_quantity' => 'required|integer',
            'reason' => 'required|string|max:500',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $variant = !empty($validated['variant_id']) ? ProductVariant::find($validated['variant_id']) : null;
        $currentStock = $variant ? $variant->stock_quantity : $product->stock_quantity;

        if ($currentStock + $validated['adjustment_quantity'] < 0) {
            return response()->json([
                'message' => "Adjustment cannot reduce stock below 0. Current stock is {$currentStock}.",
            ], 422);
        }

        $movement = InventoryCostingService::adjustStockManually(
            $product,
            $variant,
            $validated['adjustment_quantity'],
            $validated['reason'],
            $request->user(),
            isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null
        );

        AuditLog::log(
            $request->user(),
            'inventory.manual_adjustment',
            'Product',
            $product->id,
            "Adjusted stock for {$product->name} by {$validated['adjustment_quantity']} units. Reason: {$validated['reason']}."
        );

        return response()->json([
            'message' => "Stock successfully adjusted for '{$product->name}'.",
            'movement' => $movement,
            'new_stock_quantity' => $product->fresh()->stock_quantity,
        ]);
    }
}
