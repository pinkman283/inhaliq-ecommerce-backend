<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PromotionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartValidationController extends Controller
{
    /**
     * Stateless Cart & Stock Verification Endpoint.
     * Validates client cart items against live catalog availability, stock, and pricing.
     */
    public function validateCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'present|array',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string',
            'claimed_coupon_id' => 'nullable|integer',
        ]);

        $rawItems = $validated['items'];

        if (empty($rawItems)) {
            return response()->json([
                'is_valid' => true,
                'has_changes' => false,
                'items' => [],
                'summary' => [
                    'total_items' => 0,
                    'subtotal' => 0.00,
                    'has_out_of_stock' => false,
                    'has_price_changes' => false,
                    'has_inactive_items' => false,
                    'notices' => [],
                ],
            ]);
        }

        // Batch fetch all required products and variants in 2 optimized queries (Zero N+1)
        $productIds = array_values(array_unique(array_column($rawItems, 'product_id')));
        $variantIds = array_values(array_filter(array_unique(array_column($rawItems, 'variant_id'))));

        $products = Product::whereIn('id', $productIds)
            ->with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants'])
            ->get()
            ->keyBy('id');

        $variants = !empty($variantIds)
            ? ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id')
            : collect([]);

        $evaluatedItems = [];
        $notices = [];
        $isValidCart = true;
        $hasChanges = false;
        $hasOutOfStock = false;
        $hasPriceChanges = false;
        $hasInactiveItems = false;
        $runningSubtotal = 0.00;

        foreach ($rawItems as $item) {
            $productId = (int) $item['product_id'];
            $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
            $requestedQty = (int) $item['quantity'];
            $clientPrice = isset($item['price']) ? (float) $item['price'] : null;

            /** @var Product|null $product */
            $product = $products->get($productId);

            // CASE A: Product deleted or missing
            if (!$product) {
                $isValidCart = false;
                $hasChanges = true;
                $notices[] = "A product in your cart is no longer available and was removed.";
                $evaluatedItems[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'status' => 'product_not_found',
                    'is_valid' => false,
                    'requested_quantity' => $requestedQty,
                    'available_stock' => 0,
                    'normalized_quantity' => 0,
                    'unit_price' => 0.00,
                    'total_price' => 0.00,
                    'message' => 'Product is no longer available.',
                    'product' => null,
                    'variant' => null,
                ];
                continue;
            }

            // CASE B: Product is inactive
            if (!$product->is_active) {
                $isValidCart = false;
                $hasChanges = true;
                $hasInactiveItems = true;
                $notices[] = "'{$product->name}' is currently unavailable and cannot be purchased.";
                $evaluatedItems[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'status' => 'inactive',
                    'is_valid' => false,
                    'requested_quantity' => $requestedQty,
                    'available_stock' => 0,
                    'normalized_quantity' => 0,
                    'unit_price' => (float) $product->price,
                    'total_price' => 0.00,
                    'message' => "'{$product->name}' is currently unavailable.",
                    'product' => (new ProductResource($product))->resolve(),
                    'variant' => null,
                ];
                continue;
            }

            // Variant evaluations
            $variant = null;
            $unitPrice = (float) $product->price;
            $priceModifier = 0.00;
            $availableStock = (int) $product->stock_quantity;

            if ($variantId !== null) {
                /** @var ProductVariant|null $variant */
                $variant = $variants->get($variantId);

                // CASE C: Variant deleted or missing
                if (!$variant) {
                    $isValidCart = false;
                    $hasChanges = true;
                    $notices[] = "A selected variant for '{$product->name}' is no longer available.";
                    $evaluatedItems[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'status' => 'variant_not_found',
                        'is_valid' => false,
                        'requested_quantity' => $requestedQty,
                        'available_stock' => 0,
                        'normalized_quantity' => 0,
                        'unit_price' => $unitPrice,
                        'total_price' => 0.00,
                        'message' => 'Selected variant is no longer available.',
                        'product' => (new ProductResource($product))->resolve(),
                        'variant' => null,
                    ];
                    continue;
                }

                // CASE D: Variant belongs to another product (stale/corrupted local data)
                if ((int) $variant->product_id !== $productId) {
                    $isValidCart = false;
                    $hasChanges = true;
                    $notices[] = "Variant mismatch detected for '{$product->name}'.";
                    $evaluatedItems[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'status' => 'variant_mismatch',
                        'is_valid' => false,
                        'requested_quantity' => $requestedQty,
                        'available_stock' => 0,
                        'normalized_quantity' => 0,
                        'unit_price' => $unitPrice,
                        'total_price' => 0.00,
                        'message' => 'Selected option does not match this product.',
                        'product' => (new ProductResource($product))->resolve(),
                        'variant' => null,
                    ];
                    continue;
                }

                // CASE D2: Variant is inactive
                if (!$variant->is_active) {
                    $isValidCart = false;
                    $hasChanges = true;
                    $hasInactiveItems = true;
                    $notices[] = "Selected option for '{$product->name}' is currently unavailable.";
                    $evaluatedItems[] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'status' => 'inactive_variant',
                        'is_valid' => false,
                        'requested_quantity' => $requestedQty,
                        'available_stock' => 0,
                        'normalized_quantity' => 0,
                        'unit_price' => $variant->effective_price,
                        'total_price' => 0.00,
                        'message' => "Selected option for '{$product->name}' is currently unavailable.",
                        'product' => (new ProductResource($product))->resolve(),
                        'variant' => (new ProductVariantResource($variant))->resolve(),
                    ];
                    continue;
                }

                $availableStock = (int) $variant->stock_quantity;
                $unitPrice = $variant->effective_price;
                $priceModifier = $variant->price !== null
                    ? round($unitPrice - (float) $product->price, 2)
                    : (float) ($variant->price_modifier ?? 0);
            }

            // Price change detection
            $priceChanged = false;
            if ($clientPrice !== null && abs($clientPrice - $unitPrice) > 0.009) {
                $priceChanged = true;
                $hasPriceChanges = true;
                $hasChanges = true;
                $notices[] = "Price for '{$product->name}'" . ($variant ? " ({$variant->name})" : "") . " was updated from $" . number_format($clientPrice, 2) . " to $" . number_format($unitPrice, 2) . ".";
            }

            // CASE E & Out of Stock evaluations
            if ($availableStock <= 0) {
                $isValidCart = false;
                $hasChanges = true;
                $hasOutOfStock = true;
                $notices[] = "'{$product->name}'" . ($variant ? " ({$variant->name})" : "") . " is out of stock.";
                $evaluatedItems[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'status' => 'out_of_stock',
                    'is_valid' => false,
                    'requested_quantity' => $requestedQty,
                    'available_stock' => 0,
                    'normalized_quantity' => 0,
                    'unit_price' => $unitPrice,
                    'price_modifier' => $priceModifier,
                    'total_price' => 0.00,
                    'price_changed' => $priceChanged,
                    'original_client_price' => $clientPrice,
                    'message' => 'Item is currently out of stock.',
                    'product' => (new ProductResource($product))->resolve(),
                    'variant' => $variant ? (new ProductVariantResource($variant))->resolve() : null,
                ];
                continue;
            }

            // Quantity adjustment check
            $normalizedQty = $requestedQty;
            $quantityAdjusted = false;
            if ($requestedQty > $availableStock) {
                $normalizedQty = $availableStock;
                $quantityAdjusted = true;
                $hasChanges = true;
                $notices[] = "Only {$availableStock} available for '{$product->name}'" . ($variant ? " ({$variant->name})" : "") . ". Cart quantity was adjusted to {$availableStock}.";
            }

            $itemTotal = round($unitPrice * $normalizedQty, 2);
            $runningSubtotal += $itemTotal;

            $status = 'valid';
            if ($quantityAdjusted) {
                $status = 'quantity_adjusted';
            } elseif ($priceChanged) {
                $status = 'price_changed';
            }

            $evaluatedItems[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'status' => $status,
                'is_valid' => true,
                'requested_quantity' => $requestedQty,
                'available_stock' => $availableStock,
                'normalized_quantity' => $normalizedQty,
                'unit_price' => $unitPrice,
                'price_modifier' => $priceModifier,
                'total_price' => $itemTotal,
                'price_changed' => $priceChanged,
                'original_client_price' => $clientPrice,
                'message' => $quantityAdjusted
                    ? "Only {$availableStock} available. Quantity adjusted."
                    : ($priceChanged ? "Price updated to current catalog rate." : "Item is valid and available."),
                'product' => (new ProductResource($product))->resolve(),
                'variant' => $variant ? (new ProductVariantResource($variant))->resolve() : null,
            ];
        }

        // Authoritative Coupon Evaluation against current cart
        $couponResult = null;
        $couponCode = !empty($validated['coupon_code']) ? strtoupper(trim($validated['coupon_code'])) : null;
        $claimedCouponId = $validated['claimed_coupon_id'] ?? null;

        if ($couponCode || $claimedCouponId) {
            $validItems = array_values(array_filter($evaluatedItems, function ($it) {
                return $it['is_valid'] && $it['normalized_quantity'] > 0;
            }));

            if (empty($validItems)) {
                $hasChanges = true;
                $notices[] = "Promo code was removed because there are no available items in your cart.";
                $couponResult = [
                    'valid' => false,
                    'code' => $couponCode,
                    'discount_amount' => 0.00,
                    'message' => 'Cart contains no valid items.',
                ];
            } else {
                $itemsForPromo = array_map(function ($it) {
                    return [
                        'product_id' => $it['product_id'],
                        'variant_id' => $it['variant_id'],
                        'quantity' => $it['normalized_quantity'],
                        'unit_price' => $it['unit_price'],
                    ];
                }, $validItems);

                $user = $request->user('sanctum');
                $eval = PromotionEngine::evaluateCart(
                    $itemsForPromo,
                    $user,
                    $user?->email,
                    $couponCode,
                    $claimedCouponId
                );

                if ($eval['valid'] && !empty($eval['applied_promotions'])) {
                    $applied = $eval['applied_promotions'][0];
                    $discountAmt = min($runningSubtotal, (float) ($eval['order_discount'] + $eval['shipping_discount']));
                    $couponResult = [
                        'valid' => true,
                        'code' => $applied['code'] ?? $couponCode,
                        'type' => $applied['discount_type'],
                        'discount_amount' => round($discountAmt, 2),
                        'promotion_id' => $applied['promotion_id'] ?? null,
                        'promotion_name' => $applied['promotion_name'] ?? null,
                        'message' => 'Coupon verified and active.',
                    ];
                } else {
                    $hasChanges = true;
                    $reason = $eval['message'] ?: 'Coupon is no longer valid or eligible for your cart items.';
                    $notices[] = $couponCode ? "Promo code '{$couponCode}' is invalid: {$reason}" : "Voucher is invalid: {$reason}";
                    $couponResult = [
                        'valid' => false,
                        'code' => $couponCode,
                        'discount_amount' => 0.00,
                        'message' => $reason,
                    ];
                }
            }
        }

        $discountTotal = $couponResult && $couponResult['valid'] ? (float) $couponResult['discount_amount'] : 0.00;
        $finalTotal = max(0.00, round($runningSubtotal - $discountTotal, 2));

        return response()->json([
            'is_valid' => $isValidCart,
            'has_changes' => $hasChanges,
            'items' => $evaluatedItems,
            'summary' => [
                'total_items' => array_sum(array_column($evaluatedItems, 'normalized_quantity')),
                'subtotal' => round($runningSubtotal, 2),
                'discount_amount' => $discountTotal,
                'total' => $finalTotal,
                'has_out_of_stock' => $hasOutOfStock,
                'has_price_changes' => $hasPriceChanges,
                'has_inactive_items' => $hasInactiveItems,
                'notices' => array_values(array_unique($notices)),
            ],
            'coupon' => $couponResult,
        ]);
    }
}
