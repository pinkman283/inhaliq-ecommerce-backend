<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\PromotionCode;
use App\Models\PromotionCustomerRestriction;
use App\Models\PromotionProductTarget;
use App\Models\PromotionRedemption;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PromotionEngine
{
    /**
     * Authoritatively evaluate promotions, automatic discounts, codes, and claims for a cart.
     *
     * @param array<int, array{product_id: int, variant_id?: int|null, quantity: int, unit_price?: float}> $cartItems
     * @param User|null $user
     * @param string|null $customerEmail
     * @param string|null $code
     * @param int|null $claimedCouponId ID of the PromotionClaim
     * @param float $baseShippingRate
     * @param string $paymentMethod
     * @param string|null $customerPhone
     * @return array
     */
    public static function evaluateCart(
        array $cartItems,
        ?User $user = null,
        ?string $customerEmail = null,
        ?string $code = null,
        ?int $claimedCouponId = null,
        float $baseShippingRate = 0.00,
        string $paymentMethod = 'cash_on_delivery',
        ?string $customerPhone = null
    ): array {
        $vatEnabled = Setting::isVatEnabled();
        $vatRate = $vatEnabled ? Setting::getVatRate() : 0.0;

        if (empty($cartItems)) {
            return [
                'valid' => true,
                'subtotal' => 0.00,
                'item_discount' => 0.00,
                'order_discount' => 0.00,
                'shipping_discount' => 0.00,
                'total_discount' => 0.00,
                'shipping_amount' => 0.00,
                'tax_amount' => 0.00,
                'vat_amount' => 0.00,
                'vat_rate' => round($vatRate, 2),
                'vat_enabled' => $vatEnabled,
                'grand_total' => 0.00,
                'applied_promotions' => [],
                'items' => [],
                'message' => 'Cart is empty.',
            ];
        }

        // 1. Hydrate cart items with authoritative database prices, categories, and brands
        $productIds = array_column($cartItems, 'product_id');
        $products = Product::whereIn('id', $productIds)->with(['category'])->get()->keyBy('id');

        $hydratedItems = [];
        $subtotal = 0.00;
        $totalQuantity = 0;

        foreach ($cartItems as $ci) {
            $prodId = $ci['product_id'];
            if (!$products->has($prodId)) {
                continue;
            }
            $product = $products->get($prodId);
            $qty = max(1, (int) $ci['quantity']);
            $unitPrice = (float) $product->price;

            // Check variant price if variant present
            if (!empty($ci['variant_id'])) {
                $variant = \App\Models\ProductVariant::find($ci['variant_id']);
                if ($variant && $variant->product_id === $product->id) {
                    $unitPrice = $variant->effective_price;
                }
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal += $lineTotal;
            $totalQuantity += $qty;

            $hydratedItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'variant_id' => $ci['variant_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $appliedPromotions = [];
        $totalDiscount = 0.00;
        $itemDiscount = 0.00;
        $orderDiscount = 0.00;
        $shippingDiscount = 0.00;
        $codeErrorMessage = null;
        $claimErrorMessage = null;

        // 2. Discover and evaluate Automatic Discounts
        $automaticPromotions = Promotion::where('promotion_type', 'automatic_discount')
            ->activeSchedule()
            ->orderByDesc('priority')
            ->get();

        foreach ($automaticPromotions as $autoPromo) {
            if (!self::canCombine($autoPromo, $appliedPromotions)) {
                continue;
            }

            // Per-customer limit check for automatic promotions
            if ($autoPromo->per_customer_usage_limit !== null) {
                $alreadyRedeemed = 0;
                $normalizedEmail = $customerEmail ? self::normalizeEmail($customerEmail) : null;
                if ($user) {
                    $alreadyRedeemed = PromotionRedemption::where('promotion_id', $autoPromo->id)
                        ->where(function ($q) use ($user, $normalizedEmail) {
                            $q->where('user_id', $user->id);
                            if ($normalizedEmail) {
                                $q->orWhere('customer_email', $normalizedEmail)
                                  ->orWhere('customer_email', $user->email);
                            }
                        })
                        ->where('status', 'completed')
                        ->count();
                } elseif ($normalizedEmail) {
                    $alreadyRedeemed = PromotionRedemption::where('promotion_id', $autoPromo->id)
                        ->where('customer_email', $normalizedEmail)
                        ->where('status', 'completed')
                        ->count();
                }

                if ($alreadyRedeemed >= $autoPromo->per_customer_usage_limit) {
                    continue;
                }
            }

            $eval = self::evaluateSinglePromotion($autoPromo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate, $customerPhone);
            if ($eval['eligible'] && $eval['discount_amount'] > 0) {
                $appliedPromotions[] = [
                    'promotion_id' => $autoPromo->id,
                    'promotion_name' => $autoPromo->name,
                    'code' => null,
                    'type' => 'automatic_discount',
                    'discount_type' => $autoPromo->discount_type,
                    'discount_amount' => $eval['discount_amount'],
                    'breakdown' => $eval['breakdown'],
                ];

                if ($autoPromo->discount_type === 'free_shipping') {
                    $shippingDiscount = max($shippingDiscount, $eval['discount_amount']);
                } else {
                    $totalDiscount += $eval['discount_amount'];
                    $orderDiscount += $eval['discount_amount'];
                }

                // If non-stackable, stop applying further automatic discounts
                if (!$autoPromo->is_stackable) {
                    break;
                }
            }
        }

        // 3. Evaluate Claimed Coupon if specified
        $claimedClaim = null;
        if (!empty($claimedCouponId)) {
            $claimedClaim = PromotionClaim::with('promotion')->find($claimedCouponId);
            if (!$claimedClaim) {
                $claimErrorMessage = 'Claimed coupon record not found.';
            } elseif (!$user) {
                $claimErrorMessage = 'Please log in to redeem your claimed coupon voucher.';
            } elseif ($claimedClaim->user_id !== $user->id) {
                $claimErrorMessage = 'This coupon claim belongs to a different customer account.';
            } elseif ($claimedClaim->status === 'redeemed') {
                $claimErrorMessage = 'This claimed coupon has already been redeemed.';
            } elseif ($claimedClaim->status === 'expired' || ($claimedClaim->expires_at && $claimedClaim->expires_at->isPast())) {
                $claimErrorMessage = 'This claimed coupon has expired.';
            } elseif (!$claimedClaim->promotion || $claimedClaim->promotion->status !== 'active') {
                $claimErrorMessage = 'This promotional campaign is currently inactive.';
            } elseif (!self::canCombine($claimedClaim->promotion, $appliedPromotions)) {
                $claimErrorMessage = "Claimed coupon '{$claimedClaim->promotion->name}' cannot be combined with existing promotions in your cart.";
            } else {
                $claimPromo = $claimedClaim->promotion;
                $eval = self::evaluateSinglePromotion($claimPromo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate, $customerPhone);
                if (!$eval['eligible']) {
                    $claimErrorMessage = $eval['reason'];
                } else {
                    $amount = $eval['discount_amount'];
                    if ($claimPromo->discount_type === 'free_shipping') {
                        $shippingDiscount = max($shippingDiscount, $amount);
                    } else {
                        $totalDiscount += $amount;
                        $orderDiscount += $amount;
                    }
                    $appliedPromotions[] = [
                        'promotion_id' => $claimPromo->id,
                        'promotion_name' => $claimPromo->name,
                        'claim_id' => $claimedClaim->id,
                        'code' => $claimedClaim->claimed_code,
                        'type' => 'claimable_coupon',
                        'discount_type' => $claimPromo->discount_type,
                        'discount_amount' => $amount,
                        'breakdown' => $eval['breakdown'],
                    ];
                }
            }
        }

        // 4. Evaluate Promo Code if specified
        if (!empty($code) && empty($claimedClaim)) {
            $cleanCode = strtoupper(trim($code));
            $promoCode = PromotionCode::where('code', $cleanCode)->with('promotion')->first();

            if (!$promoCode) {
                // Fallback 1: check direct code in promotions table
                $directPromo = Promotion::where('slug', $cleanCode)->orWhere('name', $cleanCode)->first();
                if ($directPromo) {
                    $promoCode = (object) [
                        'id' => null,
                        'code' => $cleanCode,
                        'promotion' => $directPromo,
                        'is_active' => $directPromo->status === 'active',
                        'usage_limit' => $directPromo->total_usage_limit,
                        'used_count' => $directPromo->total_used_count,
                    ];
                } else {
                    // Fallback 2: check coupons table (legacy and admin coupon manager)
                    $coupon = Coupon::where('code', $cleanCode)->first();
                    if ($coupon) {
                        $virtualPromo = new Promotion([
                            'name' => 'Coupon ' . $coupon->code,
                            'slug' => strtolower($coupon->code),
                            'promotion_type' => 'discount_code',
                            'discount_type' => $coupon->type === 'percentage' ? 'percentage' : 'fixed_amount',
                            'discount_value' => (float) $coupon->value,
                            'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
                            'min_order_amount' => (float) ($coupon->min_order_amount ?? 0),
                            'applies_to' => 'entire_order',
                            'status' => $coupon->is_active ? 'active' : 'inactive',
                            'starts_at' => $coupon->starts_at,
                            'expires_at' => $coupon->expires_at,
                            'total_usage_limit' => $coupon->usage_limit,
                            'total_used_count' => (int) $coupon->used_count,
                            'customer_eligibility' => 'all',
                            'is_stackable' => false,
                        ]);
                        $virtualPromo->setRelation('productTargets', collect([]));
                        $virtualPromo->setRelation('customerRestrictions', collect([]));
                        $virtualPromo->setRelation('codes', collect([]));

                        $promoCode = (object) [
                            'id' => null,
                            'code' => $coupon->code,
                            'promotion' => $virtualPromo,
                            'is_active' => (bool) $coupon->is_active,
                            'usage_limit' => $coupon->usage_limit,
                            'used_count' => (int) $coupon->used_count,
                            'coupon_id' => $coupon->id,
                        ];
                    } else {
                        $codeErrorMessage = "Promo code '{$cleanCode}' is invalid.";
                    }
                }
            }

            if ($promoCode && !empty($promoCode->promotion)) {
                $promo = $promoCode->promotion;

                if (!$promoCode->is_active || $promo->status !== 'active') {
                    $codeErrorMessage = "Promo code '{$cleanCode}' is currently inactive.";
                } elseif ($promo->starts_at && $promo->starts_at->isFuture()) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' campaign starts on {$promo->starts_at->format('M d, Y')}.";
                } elseif ($promo->expires_at && $promo->expires_at->isPast()) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' has expired on {$promo->expires_at->format('M d, Y')}.";
                } elseif ($promo->total_usage_limit !== null && $promo->total_used_count >= $promo->total_usage_limit) {
                    $codeErrorMessage = "The redemption limit for promotion '{$promo->name}' has been reached.";
                } elseif ($promoCode->usage_limit !== null && $promoCode->used_count >= $promoCode->usage_limit) {
                    $codeErrorMessage = "The redemption limit for promo code '{$cleanCode}' has been reached.";
                } elseif (!self::canCombine($promo, $appliedPromotions)) {
                    $codeErrorMessage = "Promo code '{$cleanCode}' cannot be combined with existing promotions in your cart.";
                } else {
                    // Check per-customer limit with email normalization & phone checking
                    $alreadyRedeemedCount = 0;
                    $normalizedEmail = $customerEmail ? self::normalizeEmail($customerEmail) : null;
                    $cleanPhone = $customerPhone ? preg_replace('/[^0-9]/', '', $customerPhone) : null;

                    if ($promo->per_customer_usage_limit !== null) {
                        if ($user) {
                            $alreadyRedeemedCount = PromotionRedemption::where(function ($q) use ($promo, $cleanCode) {
                                    if ($promo->id) {
                                        $q->where('promotion_id', $promo->id);
                                    } else {
                                        $q->where('code_used', $cleanCode);
                                    }
                                })
                                ->where(function ($q) use ($user, $normalizedEmail) {
                                    $q->where('user_id', $user->id);
                                    if ($normalizedEmail) {
                                        $q->orWhere('customer_email', $normalizedEmail)
                                          ->orWhere('customer_email', $user->email);
                                    }
                                })
                                ->where('status', 'completed')
                                ->count();
                        } elseif ($normalizedEmail) {
                            $alreadyRedeemedCount = PromotionRedemption::where(function ($q) use ($promo, $cleanCode) {
                                    if ($promo->id) {
                                        $q->where('promotion_id', $promo->id);
                                    } else {
                                        $q->where('code_used', $cleanCode);
                                    }
                                })
                                ->where(function ($q) use ($normalizedEmail, $cleanPhone) {
                                    $q->where('customer_email', $normalizedEmail);
                                    if ($cleanPhone && strlen($cleanPhone) >= 7) {
                                        $q->orWhereHas('order', function ($oq) use ($cleanPhone) {
                                            $oq->where('customer_phone', 'like', "%{$cleanPhone}%");
                                        });
                                    }
                                })
                                ->where('status', 'completed')
                                ->count();
                        }
                    }

                    if ($promo->per_customer_usage_limit !== null && $alreadyRedeemedCount >= $promo->per_customer_usage_limit) {
                        $codeErrorMessage = "You have reached the maximum allowed redemptions ({$promo->per_customer_usage_limit}) for promo code '{$cleanCode}'.";
                    } else {
                        // Evaluate promotion eligibility rules
                        $eval = self::evaluateSinglePromotion($promo, $hydratedItems, $subtotal, $totalQuantity, $user, $customerEmail, $paymentMethod, $baseShippingRate, $customerPhone);
                        if (!$eval['eligible']) {
                            $codeErrorMessage = $eval['reason'];
                        } else {
                            $amount = $eval['discount_amount'];
                            if ($promo->discount_type === 'free_shipping') {
                                $shippingDiscount = max($shippingDiscount, $amount);
                            } else {
                                $totalDiscount += $amount;
                                $orderDiscount += $amount;
                            }
                            $appliedPromotions[] = [
                                'promotion_id' => $promo->id,
                                'coupon_id' => $promoCode->coupon_id ?? null,
                                'promotion_name' => $promo->name,
                                'code' => $cleanCode,
                                'type' => $promo->promotion_type,
                                'discount_type' => $promo->discount_type,
                                'discount_amount' => $amount,
                                'breakdown' => $eval['breakdown'],
                            ];
                        }
                    }
                }
            }
        }

        // Cap discount so it cannot exceed subtotal
        $orderDiscount = min($subtotal, round($orderDiscount, 2));
        $totalDiscount = $orderDiscount + $shippingDiscount;

        // Shipping & VAT calculation
        $effectiveShipping = max(0.00, $baseShippingRate - $shippingDiscount);
        $taxable = max(0.00, $subtotal - $orderDiscount);

        $vatAmount = ($vatEnabled && $vatRate > 0) ? round($taxable * ($vatRate / 100), 2) : 0.00;
        $grandTotal = round($taxable + $effectiveShipping + $vatAmount, 2);

        $hasError = !empty($codeErrorMessage) || !empty($claimErrorMessage);
        $errorMessage = $codeErrorMessage ?: $claimErrorMessage;

        // Allocate order discount deterministically to line items
        $allocatedItems = self::allocateDiscountToItems($hydratedItems, $orderDiscount, $vatRate);

        return [
            'valid' => !$hasError,
            'subtotal' => round($subtotal, 2),
            'item_discount' => round($itemDiscount, 2),
            'order_discount' => round($orderDiscount, 2),
            'shipping_discount' => round($shippingDiscount, 2),
            'total_discount' => round($totalDiscount, 2),
            'shipping_amount' => round($effectiveShipping, 2),
            'base_shipping_rate' => round($baseShippingRate, 2),
            'tax_amount' => round($vatAmount, 2),
            'vat_amount' => round($vatAmount, 2),
            'vat_rate' => round($vatRate, 2),
            'vat_enabled' => $vatEnabled,
            'grand_total' => round($grandTotal, 2),
            'applied_promotions' => $appliedPromotions,
            'items' => $allocatedItems,
            'message' => $errorMessage ?: (count($appliedPromotions) > 0 ? 'Promotion applied successfully!' : null),
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Check whether a new promotion can be combined with existing applied promotions.
     */
    public static function canCombine(Promotion $newPromo, array $appliedPromotions): bool
    {
        if (empty($appliedPromotions)) {
            return true;
        }

        if (!$newPromo->is_stackable || !$newPromo->can_combine_with_other_promotions) {
            return false;
        }

        $newIsShipping = ($newPromo->discount_type === 'free_shipping' || $newPromo->applies_to === 'shipping');
        $newIsProduct = in_array($newPromo->discount_type, ['product_percentage_discount', 'product_fixed_discount', 'buy_x_get_y'])
            || $newPromo->applies_to === 'specific_products'
            || $newPromo->applies_to === 'specific_categories';
        $newIsOrder = !$newIsShipping && !$newIsProduct;

        foreach ($appliedPromotions as $applied) {
            $existingPromo = Promotion::find($applied['promotion_id']);
            if (!$existingPromo) {
                continue;
            }

            if (!$existingPromo->is_stackable || !$existingPromo->can_combine_with_other_promotions) {
                return false;
            }

            $existingIsShipping = ($existingPromo->discount_type === 'free_shipping' || $existingPromo->applies_to === 'shipping');
            $existingIsProduct = in_array($existingPromo->discount_type, ['product_percentage_discount', 'product_fixed_discount', 'buy_x_get_y'])
                || $existingPromo->applies_to === 'specific_products'
                || $existingPromo->applies_to === 'specific_categories';
            $existingIsOrder = !$existingIsShipping && !$existingIsProduct;

            // Check new promo's combinability flags against existing
            if ($existingIsShipping && !$newPromo->can_combine_with_shipping_discounts) {
                return false;
            }
            if ($existingIsProduct && !$newPromo->can_combine_with_product_discounts) {
                return false;
            }
            if ($existingIsOrder && !$newPromo->can_combine_with_order_discounts) {
                return false;
            }

            // Check existing promo's combinability flags against new
            if ($newIsShipping && !$existingPromo->can_combine_with_shipping_discounts) {
                return false;
            }
            if ($newIsProduct && !$existingPromo->can_combine_with_product_discounts) {
                return false;
            }
            if ($newIsOrder && !$existingPromo->can_combine_with_order_discounts) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deterministic proportional largest-remainder allocation of order discount to individual items.
     * Guarantees that sum(item.discount_amount) == totalOrderDiscount down to 0.01 cent.
     */
    public static function allocateDiscountToItems(array $hydratedItems, float $totalOrderDiscount, float $vatRate = 0.0): array
    {
        $subtotal = array_sum(array_column($hydratedItems, 'line_total'));
        if ($subtotal <= 0 || $totalOrderDiscount <= 0) {
            return array_map(function ($item) use ($vatRate) {
                $lineTotal = (float) $item['line_total'];
                $tax = $vatRate > 0 ? round($lineTotal * ($vatRate / 100), 2) : 0.00;
                return array_merge($item, [
                    'discount_amount' => 0.00,
                    'net_total' => $lineTotal,
                    'tax_amount' => $tax,
                ]);
            }, $hydratedItems);
        }

        $totalDiscountCents = (int) round($totalOrderDiscount * 100);
        $allocatedItems = [];
        $totalAllocatedCents = 0;

        foreach ($hydratedItems as $index => $item) {
            $lineTotal = (float) $item['line_total'];
            $ratio = $lineTotal / $subtotal;
            $exactCents = $ratio * $totalDiscountCents;
            $baseCents = (int) floor($exactCents);
            $remainder = $exactCents - $baseCents;

            $totalAllocatedCents += $baseCents;
            $allocatedItems[] = [
                'index' => $index,
                'item' => $item,
                'base_cents' => $baseCents,
                'remainder' => $remainder,
                'line_total' => $lineTotal,
            ];
        }

        // Distribute remaining cents using largest remainder
        $remainingCents = $totalDiscountCents - $totalAllocatedCents;
        if ($remainingCents > 0) {
            usort($allocatedItems, function ($a, $b) {
                return $b['remainder'] <=> $a['remainder'];
            });

            for ($i = 0; $i < $remainingCents && $i < count($allocatedItems); $i++) {
                $allocatedItems[$i]['base_cents'] += 1;
            }
        }

        // Restore original order
        usort($allocatedItems, function ($a, $b) {
            return $a['index'] <=> $b['index'];
        });

        $result = [];
        foreach ($allocatedItems as $alloc) {
            $item = $alloc['item'];
            $discount = round($alloc['base_cents'] / 100, 2);
            $discount = min($alloc['line_total'], $discount);
            $netTotal = round(max(0.00, $alloc['line_total'] - $discount), 2);
            $tax = $vatRate > 0 ? round($netTotal * ($vatRate / 100), 2) : 0.00;

            $result[] = array_merge($item, [
                'discount_amount' => $discount,
                'net_total' => $netTotal,
                'tax_amount' => $tax,
            ]);
        }

        return $result;
    }

    /**
     * Evaluates a single promotion against the cart items and customer profile.
     */
    public static function evaluateSinglePromotion(
        Promotion $promo,
        array $hydratedItems,
        float $subtotal,
        int $totalQuantity,
        ?User $user,
        ?string $customerEmail,
        string $paymentMethod,
        float $shippingRate,
        ?string $customerPhone = null
    ): array {
        // 1. Min / Max Order Spend
        if ($promo->min_order_amount > 0 && $subtotal < (float) $promo->min_order_amount) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Minimum order amount of \${$promo->min_order_amount} required to qualify for {$promo->name}.",
                'breakdown' => null,
            ];
        }
        if ($promo->max_order_amount !== null && $subtotal > (float) $promo->max_order_amount) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Order exceeds the maximum spending limit of \${$promo->max_order_amount} for {$promo->name}.",
                'breakdown' => null,
            ];
        }

        // 2. Min / Max Quantity
        if ($promo->min_quantity !== null && $totalQuantity < $promo->min_quantity) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "A minimum of {$promo->min_quantity} items is required for {$promo->name}.",
                'breakdown' => null,
            ];
        }
        if ($promo->max_quantity !== null && $totalQuantity > $promo->max_quantity) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "Cart exceeds the maximum limit of {$promo->max_quantity} items for {$promo->name}.",
                'breakdown' => null,
            ];
        }

        // 3. Customer Eligibility
        if ($promo->customer_eligibility === 'first_order_only') {
            $pastOrdersQuery = Order::whereNotIn('order_status', ['cancelled']);
            $pastOrdersQuery->where(function ($q) use ($user, $customerEmail, $customerPhone) {
                $hasClause = false;
                if ($user) {
                    $q->where('user_id', $user->id);
                    $hasClause = true;
                }
                if ($customerEmail) {
                    $cleanEmail = strtolower(trim($customerEmail));
                    if ($hasClause) {
                        $q->orWhere('customer_email', $cleanEmail);
                    } else {
                        $q->where('customer_email', $cleanEmail);
                        $hasClause = true;
                    }
                }
                if ($customerPhone) {
                    $cleanPhone = preg_replace('/\D/', '', $customerPhone);
                    if ($cleanPhone) {
                        if ($hasClause) {
                            $q->orWhere('customer_phone', $cleanPhone);
                        } else {
                            $q->where('customer_phone', $cleanPhone);
                        }
                    }
                }
            });

            if ($pastOrdersQuery->count() > 0) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotional discount is strictly reserved for your first order.",
                    'breakdown' => null,
                ];
            }
        } elseif ($promo->customer_eligibility === 'existing_customers') {
            $pastOrdersQuery = Order::whereNotIn('order_status', ['cancelled']);
            $pastOrdersQuery->where(function ($q) use ($user, $customerEmail, $customerPhone) {
                $hasClause = false;
                if ($user) {
                    $q->where('user_id', $user->id);
                    $hasClause = true;
                }
                if ($customerEmail) {
                    $cleanEmail = strtolower(trim($customerEmail));
                    if ($hasClause) {
                        $q->orWhere('customer_email', $cleanEmail);
                    } else {
                        $q->where('customer_email', $cleanEmail);
                        $hasClause = true;
                    }
                }
                if ($customerPhone) {
                    $cleanPhone = preg_replace('/\D/', '', $customerPhone);
                    if ($cleanPhone) {
                        if ($hasClause) {
                            $q->orWhere('customer_phone', $cleanPhone);
                        } else {
                            $q->where('customer_phone', $cleanPhone);
                        }
                    }
                }
            });

            if ($pastOrdersQuery->count() === 0) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotion is only valid for returning customers with previous orders.",
                    'breakdown' => null,
                ];
            }
        } elseif ($promo->customer_eligibility === 'specific_customers' || $promo->promotion_type === 'customer_reward' || $promo->promotion_type === 'next_order_discount') {
            // Require authenticated session to prevent guest email hijacking
            if (!$user) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "Please sign in to your account to redeem this customer-specific reward.",
                    'breakdown' => null,
                ];
            }

            $restriction = PromotionCustomerRestriction::where('promotion_id', $promo->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$restriction) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "Your customer account is not designated for this private reward.",
                    'breakdown' => null,
                ];
            }

            if ($restriction->is_used) {
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "You have already redeemed your one-time customer reward.",
                    'breakdown' => null,
                ];
            }
        }

        // 4. Payment Method Restrictions
        if (!empty($promo->payment_methods) && is_array($promo->payment_methods)) {
            if (!in_array($paymentMethod, $promo->payment_methods)) {
                $methodsList = implode(', ', $promo->payment_methods);
                return [
                    'eligible' => false,
                    'discount_amount' => 0.00,
                    'reason' => "This promotion is only applicable for payment methods: {$methodsList}.",
                    'breakdown' => null,
                ];
            }
        }

        // 5. Product / Category / Brand Targeting
        $eligibleItems = $hydratedItems;
        $targets = $promo->productTargets;

        if ($promo->applies_to !== 'entire_order' && $promo->applies_to !== 'shipping') {
            if ($targets->isNotEmpty()) {
                $inclusionTargets = $targets->where('is_exclusion', false);
                $exclusionTargets = $targets->where('is_exclusion', true);

                $eligibleItems = array_filter($hydratedItems, function ($item) use ($inclusionTargets, $exclusionTargets, $promo) {
                    // Check exclusions first
                    foreach ($exclusionTargets as $ex) {
                        if ($ex->target_type === 'product' && $item['product_id'] == $ex->target_id) return false;
                        if ($ex->target_type === 'category' && $item['category_id'] == $ex->target_id) return false;
                        if ($ex->target_type === 'brand' && $item['brand_id'] == $ex->target_id) return false;
                    }

                    if ($inclusionTargets->isEmpty()) {
                        return true;
                    }

                    // Check inclusions
                    foreach ($inclusionTargets as $in) {
                        if ($in->target_type === 'product' && $item['product_id'] == $in->target_id) return true;
                        if ($in->target_type === 'category' && $item['category_id'] == $in->target_id) return true;
                        if ($in->target_type === 'brand' && $item['brand_id'] == $in->target_id) return true;
                    }

                    return false;
                });
            }
        }

        $eligibleSubtotal = array_sum(array_column($eligibleItems, 'line_total'));
        $eligibleQuantity = array_sum(array_column($eligibleItems, 'quantity'));

        if ($promo->applies_to !== 'entire_order' && $promo->applies_to !== 'shipping' && $eligibleSubtotal <= 0) {
            return [
                'eligible' => false,
                'discount_amount' => 0.00,
                'reason' => "None of the items in your cart qualify for the {$promo->name} promotion.",
                'breakdown' => null,
            ];
        }

        // 6. Calculate Discount based on discount_type
        $discountAmount = 0.00;
        $breakdown = null;

        switch ($promo->discount_type) {
            case 'free_shipping':
                $discountAmount = (float) $shippingRate;
                $breakdown = '100% Free Express Shipping';
                break;

            case 'percentage':
            case 'product_percentage_discount':
                $targetSpend = ($promo->applies_to === 'entire_order') ? $subtotal : $eligibleSubtotal;
                $calc = ($targetSpend * (float) $promo->discount_value) / 100;
                if ($promo->max_discount_amount && $calc > (float) $promo->max_discount_amount) {
                    $calc = (float) $promo->max_discount_amount;
                }
                $discountAmount = round($calc, 2);
                $breakdown = "{$promo->discount_value}% off " . ($promo->applies_to === 'entire_order' ? 'order subtotal' : 'qualifying products');
                break;

            case 'fixed_amount':
            case 'product_fixed_discount':
                $targetSpend = ($promo->applies_to === 'entire_order') ? $subtotal : $eligibleSubtotal;
                $discountAmount = round(min((float) $promo->discount_value, $targetSpend), 2);
                $breakdown = "\${$promo->discount_value} instant reduction";
                break;

            case 'buy_x_get_y':
                $buyQty = max(1, (int) $promo->bxgy_buy_quantity);
                $getQty = max(1, (int) $promo->bxgy_get_quantity);
                $rewardPercent = (float) ($promo->bxgy_reward_discount_percent ?: 100.00);
                $bundleSize = $buyQty + $getQty;

                if ($eligibleQuantity < $bundleSize) {
                    return [
                        'eligible' => false,
                        'discount_amount' => 0.00,
                        'reason' => "Requires at least {$bundleSize} items (Buy {$buyQty}, Get {$getQty}) to activate this promotion.",
                        'breakdown' => null,
                    ];
                }

                // Flatten all eligible individual units
                $units = [];
                foreach ($eligibleItems as $item) {
                    for ($i = 0; $i < $item['quantity']; $i++) {
                        $units[] = $item['unit_price'];
                    }
                }

                sort($units);

                $fullSets = (int) floor($eligibleQuantity / $bundleSize);
                if ($promo->bxgy_max_applications !== null) {
                    $fullSets = min($fullSets, $promo->bxgy_max_applications);
                }

                $freeUnitsCount = $fullSets * $getQty;
                $bxgyDiscount = 0.00;
                for ($k = 0; $k < $freeUnitsCount && $k < count($units); $k++) {
                    $unitPrice = $units[$k];
                    $bxgyDiscount += ($unitPrice * $rewardPercent) / 100;
                }

                $discountAmount = round($bxgyDiscount, 2);
                $breakdown = "Buy {$buyQty} Get {$getQty} ({$rewardPercent}% off) applied to {$fullSets} bundle(s)";
                break;

            default:
                $discountAmount = 0.00;
        }

        return [
            'eligible' => true,
            'discount_amount' => $discountAmount,
            'reason' => null,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Atomically records the redemption for an order inside an ongoing database transaction.
     */
    public static function recordOrderRedemption(
        Order $order,
        array $evalResult,
        ?User $user,
        string $customerEmail
    ): void {
        foreach ($evalResult['applied_promotions'] as $applied) {
            $promoId = $applied['promotion_id'] ?? null;
            $couponId = $applied['coupon_id'] ?? null;
            $promo = null;

            if ($promoId) {
                // Lock promotion row for concurrency safety
                $promo = Promotion::where('id', $promoId)->lockForUpdate()->first();
                if (!$promo) {
                    continue;
                }

                // Atomic total usage limit check inside the lock
                if ($promo->total_usage_limit !== null && $promo->total_used_count >= $promo->total_usage_limit) {
                    if ($promo->promotion_type === 'automatic_discount') {
                        continue;
                    }
                    throw new InvalidArgumentException("Promotion '{$promo->name}' has reached its total redemption limit.");
                }

                // Atomic per-customer usage limit check inside the lock
                if ($promo->per_customer_usage_limit !== null) {
                    $userRedeemedCount = 0;
                    if ($user) {
                        $userRedeemedCount = PromotionRedemption::where('promotion_id', $promo->id)
                            ->where(function ($q) use ($user, $customerEmail) {
                                $q->where('user_id', $user->id);
                                $norm = self::normalizeEmail($customerEmail);
                                if ($norm) {
                                    $q->orWhere('customer_email', $norm)
                                      ->orWhere('customer_email', $user->email);
                                }
                            })
                            ->where('status', 'completed')
                            ->count();
                    } elseif (!empty($customerEmail)) {
                        $norm = self::normalizeEmail($customerEmail);
                        $userRedeemedCount = PromotionRedemption::where('promotion_id', $promo->id)
                            ->where('customer_email', $norm)
                            ->where('status', 'completed')
                            ->count();
                    }

                    if ($userRedeemedCount >= $promo->per_customer_usage_limit) {
                        if ($promo->promotion_type === 'automatic_discount') {
                            continue;
                        }
                        throw new InvalidArgumentException("You have reached the maximum allowed redemptions ({$promo->per_customer_usage_limit}) for promotion '{$promo->name}'.");
                    }
                }

                $promo->increment('total_used_count');
            } elseif ($couponId || !empty($applied['code'])) {
                // Legacy or admin coupon usage tracking
                $couponQuery = $couponId ? Coupon::where('id', $couponId) : Coupon::where('code', $applied['code']);
                $coupon = $couponQuery->lockForUpdate()->first();
                if ($coupon) {
                    if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
                        throw new InvalidArgumentException("Coupon '{$coupon->code}' has reached its total redemption limit.");
                    }
                    $coupon->increment('used_count');
                }
            }

            $codeId = null;
            if (!empty($applied['code'])) {
                $codeObj = PromotionCode::where('code', $applied['code'])->lockForUpdate()->first();
                if ($codeObj) {
                    $maxUses = $codeObj->max_uses ?? $codeObj->usage_limit ?? null;
                    if ($maxUses !== null && $codeObj->used_count >= $maxUses) {
                        throw new InvalidArgumentException("Promo code '{$codeObj->code}' has reached its usage limit.");
                    }
                    $maxPerCust = $codeObj->max_uses_per_customer ?? null;
                    if ($maxPerCust !== null) {
                        $codeCustCount = 0;
                        if ($user) {
                            $codeCustCount = PromotionRedemption::where('promotion_code_id', $codeObj->id)
                                ->where('user_id', $user->id)
                                ->where('status', 'completed')
                                ->count();
                        } elseif (!empty($customerEmail)) {
                            $codeCustCount = PromotionRedemption::where('promotion_code_id', $codeObj->id)
                                ->where('customer_email', self::normalizeEmail($customerEmail))
                                ->where('status', 'completed')
                                ->count();
                        }
                        if ($codeCustCount >= $maxPerCust) {
                            throw new InvalidArgumentException("You have reached the redemption limit for promo code '{$codeObj->code}'.");
                        }
                    }
                    $codeObj->increment('used_count');
                    $codeId = $codeObj->id;
                }
            }

            $claimId = $applied['claim_id'] ?? null;
            if ($claimId) {
                $claim = PromotionClaim::where('id', $claimId)->lockForUpdate()->first();
                if ($claim) {
                    if ($claim->status !== 'claimed') {
                        throw new InvalidArgumentException("Claimed voucher is no longer available (status: {$claim->status}).");
                    }
                    if ($claim->expires_at && $claim->expires_at->isPast()) {
                        throw new InvalidArgumentException("Claimed voucher has expired.");
                    }
                    $claim->markRedeemed($order->id);
                }
            }

            // If customer restricted reward, mark used
            if ($user && $promo) {
                PromotionCustomerRestriction::where('promotion_id', $promo->id)
                    ->where('user_id', $user->id)
                    ->update([
                        'is_used' => true,
                        'used_at' => now(),
                    ]);
            }

            // Record immutable audit redemption
            PromotionRedemption::create([
                'promotion_id' => $promo?->id,
                'promotion_code_id' => $codeId,
                'promotion_claim_id' => $claimId,
                'order_id' => $order->id,
                'user_id' => $user?->id,
                'customer_email' => self::normalizeEmail($customerEmail),
                'code_used' => $applied['code'] ?? null,
                'promotion_type' => $applied['type'] ?? ($promo ? $promo->promotion_type : 'discount_code'),
                'discount_type' => $applied['discount_type'] ?? ($promo ? $promo->discount_type : 'fixed_amount'),
                'discount_amount' => (float) ($applied['discount_amount'] ?? 0.00),
                'order_subtotal' => (float) $order->subtotal,
                'order_total' => (float) $order->total_amount,
                'status' => 'completed',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Production-standard email normalization to prevent per-customer limit bypass.
     * Strips Gmail '+' alias suffixes and dots from the username for Google Mail addresses.
     */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            $local = $parts[0];
            $domain = $parts[1];
            if (in_array($domain, ['gmail.com', 'googlemail.com'])) {
                $local = explode('+', $local)[0];
                $local = str_replace('.', '', $local);
                return $local . '@' . $domain;
            }
            $local = explode('+', $local)[0];
            return $local . '@' . $domain;
        }
        return $email;
    }

    /**
     * Reverses promotion redemptions for a cancelled or refunded order.
     * Restores claimed vouchers, decrements usage counters, and marks redemptions reversed.
     */
    public static function reverseOrderRedemptions(Order $order, string $reason = 'Order cancelled'): void
    {
        $redemptions = PromotionRedemption::where('order_id', $order->id)
            ->where('status', 'completed')
            ->get();

        foreach ($redemptions as $redemption) {
            // Decrement promotion usage counter
            if ($redemption->promotion_id) {
                $promo = Promotion::where('id', $redemption->promotion_id)->lockForUpdate()->first();
                if ($promo && $promo->total_used_count > 0) {
                    $promo->decrement('total_used_count');
                }
            }

            // Decrement legacy coupon usage counter
            if ($redemption->code_used) {
                $coupon = Coupon::where('code', $redemption->code_used)->lockForUpdate()->first();
                if ($coupon && $coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }
            }

            // Decrement code usage counter
            if ($redemption->promotion_code_id) {
                $code = PromotionCode::where('id', $redemption->promotion_code_id)->lockForUpdate()->first();
                if ($code && $code->used_count > 0) {
                    $code->decrement('used_count');
                }
            }

            // Restore claimed coupon
            if ($redemption->promotion_claim_id) {
                $claim = PromotionClaim::where('id', $redemption->promotion_claim_id)->lockForUpdate()->first();
                if ($claim) {
                    if ($claim->expires_at && $claim->expires_at->isPast()) {
                        $claim->update([
                            'status' => 'expired',
                            'order_id' => null,
                            'redeemed_at' => null,
                        ]);
                    } else {
                        $claim->restoreToClaimed();
                    }
                }
            }

            // Restore customer restriction
            if ($redemption->user_id && $redemption->promotion_id) {
                PromotionCustomerRestriction::where('promotion_id', $redemption->promotion_id)
                    ->where('user_id', $redemption->user_id)
                    ->update([
                        'is_used' => false,
                        'used_at' => null,
                    ]);
            }

            // Mark redemption reversed
            $redemption->reverse($reason);
        }
    }
}
