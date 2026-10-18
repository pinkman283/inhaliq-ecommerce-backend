<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerCartController extends Controller
{
    /**
     * Get authenticated customer's persistent server cart.
     * Evaluates live availability, stock limits, and authoritative pricing.
     */
    public function getCart(Request $request): JsonResponse
    {
        $user = $request->user();
        $cartItems = CustomerCartItem::where('user_id', $user->id)
            ->with(['product.primaryImage', 'product.images', 'product.category', 'variant'])
            ->get();

        $sanitizedItems = [];
        $notices = [];
        $runningSubtotal = 0.00;

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;

            // Clean up deleted or inactive product
            if (!$product || !$product->is_active || ($product->status && $product->status !== 'active')) {
                $cartItem->delete();
                $notices[] = "A product in your saved cart is no longer active and was removed.";
                continue;
            }

            // Variant validation
            $variant = $cartItem->variant;
            if ($cartItem->variant_id && (!$variant || $variant->product_id !== $product->id || !$variant->is_active)) {
                $cartItem->delete();
                $notices[] = "A product option in your saved cart is no longer active or valid and was removed.";
                continue;
            }

            // Live stock calculation
            $availableStock = $variant
                ? max(0, (int) $variant->stock_quantity)
                : max(0, (int) $product->stock_quantity);

            if ($availableStock <= 0) {
                $cartItem->delete();
                $notices[] = "{$product->name} is currently out of stock and was removed from your cart.";
                continue;
            }

            $currentQty = (int) $cartItem->quantity;
            if ($currentQty > $availableStock) {
                $currentQty = $availableStock;
                $cartItem->update(['quantity' => $currentQty]);
                $notices[] = "Quantity for {$product->name} was adjusted to available stock ({$availableStock}).";
            }

            $unitPrice = $variant ? $variant->effective_price : (float) $product->price;
            $totalPrice = round($unitPrice * $currentQty, 2);
            $runningSubtotal += $totalPrice;

            $sanitizedItems[] = [
                'id' => $cartItem->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'quantity' => $currentQty,
                'available_stock' => $availableStock,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'sku' => $product->sku,
                    'price' => (float) $product->price,
                    'is_active' => (bool) $product->is_active,
                    'primary_image' => $product->primaryImage?->image_url,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                    ] : null,
                ],
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'color_name' => $variant->color_name,
                    'size' => $variant->size,
                    'price_modifier' => (float) $variant->price_modifier,
                    'price' => $variant->price !== null ? (float) $variant->price : null,
                    'effective_price' => $variant->effective_price,
                    'attributes' => $variant->attributes ?: null,
                    'is_active' => (bool) $variant->is_active,
                    'stock_quantity' => (int) $variant->stock_quantity,
                ] : null,
            ];
        }

        return response()->json([
            'items' => $sanitizedItems,
            'summary' => [
                'total_items' => count($sanitizedItems),
                'total_quantity' => array_sum(array_column($sanitizedItems, 'quantity')),
                'subtotal' => round($runningSubtotal, 2),
                'notices' => $notices,
            ],
        ]);
    }

    /**
     * Synchronize customer cart items.
     */
    public function syncCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'present|array',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $rawItems = $validated['items'];

        DB::transaction(function () use ($user, $rawItems) {
            CustomerCartItem::where('user_id', $user->id)->delete();

            if (empty($rawItems)) {
                return;
            }

            // Deduplicate incoming raw items by product_id and variant_id
            $aggregated = [];
            foreach ($rawItems as $item) {
                $pid = (int) $item['product_id'];
                $vid = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                $key = "{$pid}:" . ($vid ?? 'none');

                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'quantity' => (int) $item['quantity'],
                    ];
                } else {
                    $aggregated[$key]['quantity'] += (int) $item['quantity'];
                }
            }

            $productIds = array_unique(array_column($aggregated, 'product_id'));
            $variantIds = array_filter(array_unique(array_column($aggregated, 'variant_id')));

            $products = Product::whereIn('id', $productIds)->where('is_active', true)->get()->keyBy('id');
            $variants = !empty($variantIds)
                ? ProductVariant::whereIn('id', $variantIds)->where('is_active', true)->get()->keyBy('id')
                : collect([]);

            foreach ($aggregated as $item) {
                $product = $products->get($item['product_id']);
                if (!$product) {
                    continue;
                }

                $variant = $item['variant_id'] ? $variants->get($item['variant_id']) : null;
                if ($item['variant_id'] && (!$variant || $variant->product_id !== $product->id || !$variant->is_active)) {
                    continue;
                }

                $availableStock = $variant
                    ? max(0, (int) $variant->stock_quantity)
                    : max(0, (int) $product->stock_quantity);

                if ($availableStock <= 0) {
                    continue;
                }

                $qty = min($item['quantity'], $availableStock);

                CustomerCartItem::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => $qty,
                ]);
            }
        });

        return $this->getCart($request);
    }

    /**
     * Merge guest cart into customer cart upon login/registration.
     */
    public function mergeCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guest_items' => 'present|array',
            'guest_items.*.product_id' => 'required|integer',
            'guest_items.*.variant_id' => 'nullable|integer',
            'guest_items.*.quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $guestItems = $validated['guest_items'];

        DB::transaction(function () use ($user, $guestItems) {
            // 1. Fetch current server cart items
            $existingServerItems = CustomerCartItem::where('user_id', $user->id)->get();

            // 2. Aggregate quantities by product_id and variant_id
            $aggregated = [];
            foreach ($existingServerItems as $serverItem) {
                $pid = (int) $serverItem->product_id;
                $vid = $serverItem->variant_id ? (int) $serverItem->variant_id : null;
                $key = "{$pid}:" . ($vid ?? 'none');

                $aggregated[$key] = [
                    'product_id' => $pid,
                    'variant_id' => $vid,
                    'quantity' => (int) $serverItem->quantity,
                ];
            }

            foreach ($guestItems as $guestItem) {
                $pid = (int) $guestItem['product_id'];
                $vid = !empty($guestItem['variant_id']) ? (int) $guestItem['variant_id'] : null;
                $key = "{$pid}:" . ($vid ?? 'none');

                if (isset($aggregated[$key])) {
                    // Combine quantities
                    $aggregated[$key]['quantity'] += (int) $guestItem['quantity'];
                } else {
                    $aggregated[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'quantity' => (int) $guestItem['quantity'],
                    ];
                }
            }

            // 3. Delete existing records and re-insert validated, stock-capped rows
            CustomerCartItem::where('user_id', $user->id)->delete();

            if (empty($aggregated)) {
                return;
            }

            $productIds = array_unique(array_column($aggregated, 'product_id'));
            $variantIds = array_filter(array_unique(array_column($aggregated, 'variant_id')));

            $products = Product::whereIn('id', $productIds)->where('is_active', true)->get()->keyBy('id');
            $variants = !empty($variantIds)
                ? ProductVariant::whereIn('id', $variantIds)->where('is_active', true)->get()->keyBy('id')
                : collect([]);

            foreach ($aggregated as $item) {
                $product = $products->get($item['product_id']);
                if (!$product) {
                    continue;
                }

                $variant = $item['variant_id'] ? $variants->get($item['variant_id']) : null;
                if ($item['variant_id'] && (!$variant || $variant->product_id !== $product->id || !$variant->is_active)) {
                    continue;
                }

                $availableStock = $variant
                    ? max(0, (int) $variant->stock_quantity)
                    : max(0, (int) $product->stock_quantity);

                if ($availableStock <= 0) {
                    continue;
                }

                $finalQty = min($item['quantity'], $availableStock);

                CustomerCartItem::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => $finalQty,
                ]);
            }
        });

        return $this->getCart($request);
    }

    /**
     * Clear customer cart.
     */
    public function clearCart(Request $request): JsonResponse
    {
        $user = $request->user();
        CustomerCartItem::where('user_id', $user->id)->delete();

        return response()->json([
            'message' => 'Customer cart cleared successfully.',
            'items' => [],
            'summary' => [
                'total_items' => 0,
                'total_quantity' => 0,
                'subtotal' => 0.00,
                'notices' => [],
            ],
        ]);
    }
}
