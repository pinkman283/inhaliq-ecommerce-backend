<?php

namespace App\Services;

use App\Models\GoodsReceiptItem;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemCostLayer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryCostingService
{
    /**
     * Receive physical goods from a Goods Receipt Item, create a FIFO cost layer,
     * record the inventory movement, and increment physical stock.
     */
    public static function receiveGoods(GoodsReceiptItem $item, User $actor, ?string $notes = null): InventoryCostLayer
    {
        return DB::transaction(function () use ($item, $actor, $notes) {
            $product = Product::findOrFail($item->product_id);
            $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;
            $quantity = $item->quantity_received;

            // 1. Create FIFO Cost Layer
            $layer = InventoryCostLayer::create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'goods_receipt_item_id' => $item->id,
                'unit_cost' => $item->unit_cost,
                'initial_quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'is_depleted' => $quantity <= 0,
            ]);

            // 2. Increment physical stock
            $product->increment('stock_quantity', $quantity);
            if ($variant) {
                $variant->increment('stock_quantity', $quantity);
            }

            // 3. Record Auditable Ledger Movement
            $balanceAfter = $product->fresh()->stock_quantity;
            InventoryMovement::create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'movement_type' => 'purchase_received',
                'quantity' => $quantity,
                'unit_cost' => $item->unit_cost,
                'total_cost' => $item->total_cost,
                'balance_after' => $balanceAfter,
                'reference_type' => 'GoodsReceipt',
                'reference_id' => $item->goodsReceipt?->receipt_number ?? (string) $item->goods_receipt_id,
                'user_id' => $actor->id,
                'notes' => $notes ?: "Received from PO #{$item->purchaseOrderItem?->purchaseOrder?->po_number} via GRN #{$item->goodsReceipt?->receipt_number}",
            ]);

            return $layer;
        });
    }

    /**
     * Consume FIFO cost layers for an entire Order (Online or POS).
     * Computes exact COGS and Gross Profit per item and for the entire order.
     */
    public static function fulfillOrderAndComputeCogs(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            // Idempotency check: if order has already computed COGS or allocated layers, skip duplicate fulfillment
            if ($order->orderItemCostLayers()->exists() || (float)$order->cogs_amount > 0) {
                return $order->fresh(['items.costLayers', 'posSession', 'cashier']);
            }

            $totalOrderCogs = 0.00;
            $totalOrderRevenue = 0.00;

            foreach ($order->items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                $variant = $item->variant_id ? ProductVariant::where('id', $item->variant_id)->lockForUpdate()->first() : null;
                $qtyToFulfill = $item->quantity;

                $itemCogs = 0.00;
                $remainingQty = $qtyToFulfill;

                if ($product) {
                    // Fetch oldest non-depleted cost layers for this product and variant
                    $layers = InventoryCostLayer::where('product_id', $product->id)
                        ->where('variant_id', $variant?->id)
                        ->where('is_depleted', false)
                        ->where('remaining_quantity', '>', 0)
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->get();

                    foreach ($layers as $layer) {
                        if ($remainingQty <= 0) break;

                        $take = min($layer->remaining_quantity, $remainingQty);
                        $layerCost = $take * (float) $layer->unit_cost;
                        $itemCogs += $layerCost;

                        $layer->remaining_quantity -= $take;
                        if ($layer->remaining_quantity <= 0) {
                            $layer->is_depleted = true;
                        }
                        $layer->save();

                        // Record consumption linkage
                        OrderItemCostLayer::create([
                            'order_item_id' => $item->id,
                            'inventory_cost_layer_id' => $layer->id,
                            'quantity_consumed' => $take,
                            'unit_cost' => $layer->unit_cost,
                            'total_cost' => $layerCost,
                        ]);

                        $remainingQty -= $take;
                    }

                    // If order quantity exceeded available procurement cost layers, compute with documented cost basis
                    if ($remainingQty > 0) {
                        $baseCost = ($variant && $variant->cost_price !== null && (float)$variant->cost_price > 0)
                            ? (float)$variant->cost_price
                            : ($product->cost_price !== null && (float)$product->cost_price > 0 ? (float)$product->cost_price : null);

                        if ($baseCost === null) {
                            \Illuminate\Support\Facades\Log::warning("Fulfillment for product '{$product->name}' (ID: {$product->id}) has no FIFO layer or documented cost price; defaulting COGS base cost to 0.00 to guarantee stock decrement.");
                            $baseCost = 0.00;
                        }

                        $itemCogs += ($remainingQty * $baseCost);
                    }

                    // Decrement physical stock if not already decremented
                    // (Ensure atomic update)
                    $product->decrement('stock_quantity', $qtyToFulfill);
                    if ($variant) {
                        $variant->decrement('stock_quantity', $qtyToFulfill);
                    }

                    // Record Auditable Inventory Movement
                    $movementType = $order->order_source === 'pos' ? 'pos_sale' : 'online_sale';
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'movement_type' => $movementType,
                        'quantity' => -$qtyToFulfill,
                        'unit_cost' => $qtyToFulfill > 0 ? ($itemCogs / $qtyToFulfill) : 0.00,
                        'total_cost' => $itemCogs,
                        'balance_after' => $product->fresh()->stock_quantity,
                        'reference_type' => 'Order',
                        'reference_id' => $order->order_number,
                        'user_id' => $order->cashier_user_id ?: $order->user_id,
                        'notes' => "Sale fulfillment for Order #{$order->order_number} ({$order->order_source})",
                    ]);
                }

                $itemRevenue = (float) $item->total_price;
                $itemGrossProfit = $itemRevenue - $itemCogs;
                $itemUnitCogs = $qtyToFulfill > 0 ? ($itemCogs / $qtyToFulfill) : 0.00;

                $item->update([
                    'cogs_unit_cost' => $itemUnitCogs,
                    'cogs_total' => $itemCogs,
                    'gross_profit' => $itemGrossProfit,
                ]);

                $totalOrderCogs += $itemCogs;
                $totalOrderRevenue += $itemRevenue;
            }

            $orderGrossProfit = $order->total_amount - $totalOrderCogs;

            $order->update([
                'cogs_amount' => $totalOrderCogs,
                'gross_profit' => $orderGrossProfit,
            ]);

            return $order->fresh(['items.costLayers', 'posSession', 'cashier']);
        });
    }

    /**
     * Reverse COGS & optionally restock returned/refunded items.
     */
    public static function reverseOrderRefund(Order $order, string $reason, bool $restock = true, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $restock, $actor) {
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                $variant = $item->variant_id ? ProductVariant::find($item->variant_id) : null;

                if ($restock && $product) {
                    // 1. Re-add physical stock
                    $product->increment('stock_quantity', $item->quantity);
                    if ($variant) {
                        $variant->increment('stock_quantity', $item->quantity);
                    }

                    // 2. Re-create or restore cost layer using original item COGS or documented cost
                    $restockCost = ($item->cogs_unit_cost !== null && (float)$item->cogs_unit_cost > 0)
                        ? (float)$item->cogs_unit_cost
                        : (($variant && $variant->cost_price !== null && (float)$variant->cost_price > 0)
                            ? (float)$variant->cost_price
                            : ($product->cost_price !== null && (float)$product->cost_price > 0 ? (float)$product->cost_price : 0.00));

                    InventoryCostLayer::create([
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'unit_cost' => $restockCost,
                        'initial_quantity' => $item->quantity,
                        'remaining_quantity' => $item->quantity,
                        'is_depleted' => false,
                    ]);

                    // 3. Record Auditable Ledger Movement
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'movement_type' => 'refund_restock',
                        'quantity' => $item->quantity,
                        'unit_cost' => $restockCost,
                        'total_cost' => round($item->quantity * $restockCost, 2),
                        'balance_after' => $product->fresh()->stock_quantity,
                        'reference_type' => 'Order',
                        'reference_id' => $order->order_number,
                        'user_id' => $actor?->id ?? auth()->id(),
                        'notes' => "Restock from Order #{$order->order_number} refund. Reason: {$reason}",
                    ]);
                }
            }

            return $order->fresh(['items']);
        });
    }

    /**
     * Manual stock adjustment with auditable ledger and cost layer management.
     */
    public static function adjustStockManually(
        Product $product,
        ?ProductVariant $variant,
        int $adjustmentQty,
        string $reason,
        User $actor,
        ?float $unitCost = null
    ): InventoryMovement {
        return DB::transaction(function () use ($product, $variant, $adjustmentQty, $reason, $actor, $unitCost) {
            $effectiveUnitCost = $unitCost;
            if ($effectiveUnitCost === null) {
                if ($variant && $variant->cost_price !== null && (float)$variant->cost_price > 0) {
                    $effectiveUnitCost = (float)$variant->cost_price;
                } elseif ($product->cost_price !== null && (float)$product->cost_price > 0) {
                    $effectiveUnitCost = (float)$product->cost_price;
                }
            }

            if ($adjustmentQty > 0 && ($effectiveUnitCost === null || $effectiveUnitCost < 0)) {
                throw new \InvalidArgumentException("Cannot increase inventory without a documented unit cost basis. Please specify the unit cost or ensure the product/variant cost price is set.");
            }
            $effectiveUnitCost = (float)($effectiveUnitCost ?? 0.00);
            $totalCost = abs($adjustmentQty) * $effectiveUnitCost;

            if ($adjustmentQty > 0) {
                // Stock Inflow: Create new FIFO cost layer
                InventoryCostLayer::create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'unit_cost' => $effectiveUnitCost,
                    'initial_quantity' => $adjustmentQty,
                    'remaining_quantity' => $adjustmentQty,
                    'is_depleted' => false,
                ]);

                $product->increment('stock_quantity', $adjustmentQty);
                if ($variant) {
                    $variant->increment('stock_quantity', $adjustmentQty);
                }
            } elseif ($adjustmentQty < 0) {
                // Stock Outflow / Damage / Writeoff: Deplete cost layers
                $needed = abs($adjustmentQty);
                $layers = InventoryCostLayer::where('product_id', $product->id)
                    ->where('variant_id', $variant?->id)
                    ->where('is_depleted', false)
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($layers as $layer) {
                    if ($needed <= 0) break;
                    $take = min($layer->remaining_quantity, $needed);
                    $layer->remaining_quantity -= $take;
                    if ($layer->remaining_quantity <= 0) {
                        $layer->is_depleted = true;
                    }
                    $layer->save();
                    $needed -= $take;
                }

                $product->decrement('stock_quantity', abs($adjustmentQty));
                if ($variant) {
                    $variant->decrement('stock_quantity', abs($adjustmentQty));
                }
            }

            $movementType = $adjustmentQty >= 0 ? 'manual_adjustment' : (str_contains(strtolower($reason), 'damage') ? 'damage_writeoff' : 'manual_adjustment');

            return InventoryMovement::create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'movement_type' => $movementType,
                'quantity' => $adjustmentQty,
                'unit_cost' => $effectiveUnitCost,
                'total_cost' => $totalCost,
                'balance_after' => $product->fresh()->stock_quantity,
                'reference_type' => 'ManualAdjustment',
                'reference_id' => 'ADJ-' . time(),
                'user_id' => $actor->id,
                'notes' => $reason,
            ]);
        });
    }
}
