<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\IdempotencyService;
use App\Services\OrderReturnService;
use App\Services\OrderTimelineService;
use App\Services\Payment\PaymentManager;
use App\Services\PromotionEngine;
use App\Services\ShippingZoneResolver;
use App\Services\StoreCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Strictly scoped to authenticated customer orders
        $query = Order::where('user_id', $user->id)
            ->with('items')
            ->latest();

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()]);
        }

        $perPage = $request->integer('per_page', 50);
        return response()->json($query->paginate($perPage));
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = auth('sanctum')->user() ?? $request->user();

        $order = Order::where('order_number', $orderNumber)
            ->with(['items', 'user'])
            ->firstOrFail();

        // Strictly protect customer privacy: if order belongs to a registered customer account,
        // it can only be accessed by that customer or staff/admin.
        if ($order->user_id) {
            if (!$user || ($order->user_id !== $user->id && !$user->isStaffOrAdmin())) {
                return response()->json([
                    'message' => 'Access Denied: You do not have authorization to view this customer order.',
                ], 403);
            }
        }

        return response()->json($order);
    }

    public function track(string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->orWhere('tracking_code', $orderNumber)
            ->with(['items', 'latestShipment'])
            ->firstOrFail();

        // Mask customer name for privacy protection on public tracking
        $nameParts = explode(' ', trim($order->customer_name));
        $maskedName = implode(' ', array_map(function ($part) {
            return strlen($part) > 1 ? substr($part, 0, 1) . str_repeat('*', max(1, strlen($part) - 1)) : $part;
        }, $nameParts));

        $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
        $sanitizedAddress = [
            'city' => $shippingAddress['city'] ?? 'City',
            'state' => $shippingAddress['state'] ?? null,
            'country' => $shippingAddress['country'] ?? 'Country',
        ];

        $latestShipment = $order->latestShipment;
        $carrierName = $latestShipment ? ucfirst($latestShipment->provider) : ($order->carrier ?: null);
        $trackingCode = $latestShipment ? $latestShipment->tracking_code : $order->tracking_code;
        $trackingUrl = $latestShipment?->tracking_url;
        $shipmentStatus = $latestShipment?->status ?? ($order->order_status === 'delivered' ? 'delivered' : 'pending');

        return response()->json([
            'order_number' => $order->order_number,
            'customer_name' => $maskedName,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'carrier' => $carrierName,
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'shipment_status' => $shipmentStatus,
            'courier_status_raw' => $latestShipment?->courier_status_raw,
            'delivery_attempts' => $latestShipment?->delivery_attempts ?? 0,
            'failure_reason' => $latestShipment?->failure_reason,
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at,
            'shipped_at' => $order->shipped_at,
            'delivered_at' => $order->delivered_at,
            'shipping_destination' => $sanitizedAddress,
            'items' => $order->items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // 1. IP Block Enforcement (Generic 403)
        $clientIp = $request->ip();
        if (\App\Services\CustomerRiskService::isIpBlocked($clientIp)) {
            return response()->json([
                'message' => 'Your request cannot be completed at this time.',
            ], 403);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        $user = $request->user('sanctum');

        return IdempotencyService::run($idempotencyKey, $request->all(), function () use ($request, $user, $clientIp) {
            $validated = $request->validate([
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:30',
                'shipping_address' => 'required|array',
                'shipping_address.address_line1' => 'required|string',
                'shipping_address.city' => 'required|string',
                'shipping_address.postal_code' => 'nullable|string',
                'shipping_address.country' => 'nullable|string',
                'billing_address' => 'nullable|array',
                'shipping_city_id' => 'nullable|integer',
                'shipping_zone_id' => 'nullable|integer',
                'shipping_area_id' => 'nullable|integer',
                'payment_method' => [
                    'required',
                    'string',
                    'max:50',
                    function ($attribute, $value, $fail) {
                        $paymentManager = app(PaymentManager::class);
                        if (!$paymentManager->isKnownMethod($value)) {
                            $fail('The selected payment method is invalid.');
                            return;
                        }
                        $provider = $paymentManager->driver($value);
                        if (!$provider->isConfigured()) {
                            $fail('Online payment is currently unavailable.');
                        }
                    },
                ],
                'shipping_method' => 'required|string|max:100',
                'coupon_code' => 'nullable|string',
                'claimed_coupon_id' => 'nullable|integer',
                'use_store_credit' => 'nullable|boolean',
                'store_credit_amount' => 'nullable|numeric|min:0',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.quantity' => 'required|integer|min:1',
            ], [
                'shipping_method.required' => 'Please select a delivery area.',
            ]);

            // Resolve authoritative payment provider and canonical method name
            $paymentManager = app(PaymentManager::class);
            $paymentProvider = $paymentManager->driver($validated['payment_method']);
            $canonicalPaymentMethod = $paymentManager->normalizePaymentMethod($validated['payment_method']);
            $validated['payment_method'] = $canonicalPaymentMethod;

            // Ensure address defaults
            $shippingAddress = $validated['shipping_address'];
            $shippingAddress['country'] = $shippingAddress['country'] ?? 'Bangladesh';
            $shippingAddress['postal_code'] = $shippingAddress['postal_code'] ?? '1000';
            $validated['shipping_address'] = $shippingAddress;

            // 2. Customer Account Status & Guest Unification
            if ($user) {
                if ($user->isBlocked() || $user->isSuspended()) {
                    abort(403, 'Account access restricted. Please contact customer support.');
                }
                $customerRecord = $user;
            } else {
                $email = strtolower(trim($validated['customer_email']));
                $existingCustomer = User::where('role', 'customer')->where('email', $email)->first();

                if ($existingCustomer) {
                    if ($existingCustomer->isBlocked() || $existingCustomer->isSuspended()) {
                        abort(403, 'Account access restricted. Please contact customer support.');
                    }
                    $customerRecord = $existingCustomer;
                } else {
                    $customerRecord = User::create([
                        'name' => $validated['customer_name'],
                        'email' => $email,
                        'phone' => $validated['customer_phone'] ?? null,
                        'role' => 'customer',
                        'customer_type' => 'guest',
                        'status' => 'active',
                        'password' => \Illuminate\Support\Facades\Hash::make(Str::random(32)),
                    ]);
                }
            }

            $result = DB::transaction(function () use ($request, $validated, $customerRecord, $clientIp, $paymentProvider, $canonicalPaymentMethod) {
                $subtotal = 0.00;
                $itemsToCreate = [];

                foreach ($validated['items'] as $itemData) {
                    // Concurrency-safe row locking
                    $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->firstOrFail();

                    // Catalog Integrity: Active Product check
                    if (!$product->is_active) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => ["Product '{$product->name}' is currently inactive and cannot be ordered."],
                        ]);
                    }

                    $variant = null;
                    $unitPrice = (float) $product->price;
                    $variantName = null;

                    if (!empty($itemData['variant_id'])) {
                        $variant = ProductVariant::where('id', $itemData['variant_id'])->lockForUpdate()->firstOrFail();

                        // Catalog Integrity: Variant must belong to product
                        if ((int) $variant->product_id !== (int) $product->id) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'items' => ["Selected variant does not belong to product '{$product->name}'."],
                            ]);
                        }

                        // Catalog Integrity: Active Variant check
                        if (!$variant->is_active) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'items' => ["Selected variant for product '{$product->name}' is currently inactive and cannot be ordered."],
                            ]);
                        }

                        if ($variant->stock_quantity < $itemData['quantity']) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'items' => ["Insufficient inventory for variant '{$variant->name}'. Only {$variant->stock_quantity} unit(s) available."],
                            ]);
                        }

                        $unitPrice = $variant->effective_price;
                        $variantName = $variant->name;
                    }

                    // Enforce stock availability on parent product
                    if ($product->stock_quantity < $itemData['quantity']) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => ["Insufficient inventory for product '{$product->name}'. Only {$product->stock_quantity} unit(s) available."],
                        ]);
                    }

                    $totalItemPrice = round($unitPrice * $itemData['quantity'], 2);
                    $subtotal += $totalItemPrice;

                    $productImage = $product->primaryImage?->image_url ?? $product->images->first()?->image_url;

                    $itemsToCreate[] = [
                        'product_id' => $product->id,
                        'variant_id' => $itemData['variant_id'] ?? null,
                        'product_name' => $product->name,
                        'product_sku' => $variant ? $variant->sku : $product->sku,
                        'product_image' => $productImage,
                        'variant_name' => $variantName,
                        'variant_attributes' => ($variant && !empty($variant->attributes)) ? $variant->attributes : null,
                        'unit_price' => $unitPrice,
                        'quantity' => $itemData['quantity'],
                        'total_price' => $totalItemPrice,
                    ];
                }

                // Authoritative server-side Shipping Zone & Rate Resolution
                try {
                    $shippingCalc = ShippingZoneResolver::resolveAndCalculate(
                        $validated['shipping_address'] ?? [],
                        $subtotal,
                        $validated['shipping_method'] ?? null
                    );
                } catch (\InvalidArgumentException $e) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'shipping_method' => [$e->getMessage()],
                    ]);
                }

                $shippingMethod = $shippingCalc['effective_method'];
                $baseShipping = $shippingCalc['shipping_fee'];

                $eval = PromotionEngine::evaluateCart(
                    $validated['items'],
                    $customerRecord,
                    $validated['customer_email'],
                    $validated['coupon_code'] ?? null,
                    $validated['claimed_coupon_id'] ?? null,
                    $baseShipping,
                    $validated['payment_method'],
                    $validated['customer_phone'] ?? null
                );

                // If customer explicitly entered a coupon code or claim that is invalid, reject
                if ((!empty($validated['coupon_code']) || !empty($validated['claimed_coupon_id'])) && !$eval['valid']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'coupon_code' => [$eval['message'] ?: 'Provided promo code or claimed coupon is not valid for this order.'],
                    ]);
                }

                $discount = $eval['order_discount'];
                $shipping = $eval['shipping_amount'];
                $tax = $eval['tax_amount'];
                $total = $eval['grand_total'];
                $primaryPromoId = $eval['applied_promotions'][0]['promotion_id'] ?? null;

                $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(Str::random(6));

                $order = Order::create([
                    'user_id' => $customerRecord->id,
                    'order_number' => $orderNumber,
                    'order_source' => 'online',
                    'customer_name' => $validated['customer_name'],
                    'customer_email' => $validated['customer_email'],
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'shipping_address' => $validated['shipping_address'],
                    'shipping_city_id' => $validated['shipping_city_id'] ?? null,
                    'shipping_zone_id' => $validated['shipping_zone_id'] ?? null,
                    'shipping_area_id' => $validated['shipping_area_id'] ?? null,
                    'billing_address' => $validated['billing_address'] ?? $validated['shipping_address'],
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax,
                    'shipping_amount' => $shipping,
                    'shipping_method' => $shippingMethod,
                    'discount_amount' => $discount,
                    'store_credit_amount' => 0.00,
                    'total_amount' => $total,
                    'payment_status' => 'pending',
                    'payment_method' => $canonicalPaymentMethod,
                    'payment_transaction_id' => null,
                    'order_status' => 'pending',
                    'tracking_code' => null,
                    'carrier' => null,
                    'coupon_code' => $validated['coupon_code'] ?? null,
                    'promotion_id' => $primaryPromoId,
                    'promotion_discount_details' => $eval['applied_promotions'],
                    'ip_address' => $clientIp,
                ]);

                // Authoritative Payment Registration via PaymentProvider
                $paymentResult = $paymentProvider->initiatePayment($order);
                if (!$paymentResult->success) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payment_method' => [$paymentResult->message ?: 'Failed to initialize payment.'],
                    ]);
                }

                // If customer requested store credit application, deduct atomically
                if (!empty($validated['use_store_credit'])) {
                    $requestedCredit = (float) ($validated['store_credit_amount'] ?? $total);
                    StoreCreditService::applyToOrder($order, $requestedCredit, $customerRecord);
                }

                // Record authoritative promotion redemption audit records (with concurrency safety)
                try {
                    PromotionEngine::recordOrderRedemption($order, $eval, $customerRecord, $validated['customer_email']);
                } catch (\InvalidArgumentException $ex) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'coupon_code' => [$ex->getMessage()],
                    ]);
                }

                foreach ($itemsToCreate as $index => $item) {
                    $allocated = $eval['items'][$index] ?? [];
                    $item['discount_amount'] = $allocated['discount_amount'] ?? 0.00;
                    $item['tax_amount'] = $allocated['tax_amount'] ?? 0.00;
                    $item['net_total'] = $allocated['net_total'] ?? $item['total_price'];
                    $order->items()->create($item);
                }

                // Log IP record
                \App\Models\CustomerIpLog::record($customerRecord, $clientIp, 'order_created', $order->id);

                // Execute FIFO Costing Layer Consumption & Compute COGS
                try {
                    $order = \App\Services\InventoryCostingService::fulfillOrderAndComputeCogs($order);
                } catch (\Throwable $costingEx) {
                    \Illuminate\Support\Facades\Log::warning("Inventory costing computation deferred for Order #{$order->order_number}: " . $costingEx->getMessage(), [
                        'exception' => $costingEx,
                    ]);
                }

                // Post Real Double-Entry Sale Journal Entry to General Ledger
                try {
                    \App\Services\AccountingService::postOrderSale($order);
                } catch (\Throwable $acctEx) {
                    \Illuminate\Support\Facades\Log::error("Accounting journal entry failed for Order #{$order->order_number}: " . $acctEx->getMessage(), [
                        'exception' => $acctEx,
                    ]);
                }

                // Update risk score
                \App\Services\CustomerRiskService::calculateCustomerRisk($customerRecord);

                // Record Initial Order Placed Timeline Milestone
                \App\Services\OrderTimelineService::recordEvent(
                    order: $order,
                    eventType: 'order_placed',
                    title: 'Order Placed (Cash on Delivery)',
                    description: "Order #{$order->order_number} placed by {$order->customer_name} for " . count($itemsToCreate) . " item(s). Collectable COD: ৳" . number_format($order->total_amount, 2),
                    actorName: $order->customer_name ?: 'Customer',
                    iconType: 'check',
                    metadata: [
                        'order_number' => $order->order_number,
                        'payment_method' => 'cash_on_delivery',
                        'subtotal' => $subtotal,
                        'shipping' => $shipping,
                        'total' => $total,
                    ]
                );

                // Send customer confirmation email & SMS
                \App\Services\CustomerNotificationService::sendOrderConfirmation($order);

                return $order->load('items');
            });

            return response()->json([
                'message' => 'Order created successfully',
                'order' => $result,
            ], 201);
        }, $user?->id);
    }

    /**
     * Compute authoritative server-side shipping rate based on zone and subtotal
     */
    public static function calculateAuthoritativeShippingRate(string $method, float $subtotal, array $address = []): float
    {
        try {
            if (!empty($address)) {
                $calc = ShippingZoneResolver::resolveAndCalculate($address, $subtotal, $method);
                return $calc['shipping_fee'];
            }
        } catch (\InvalidArgumentException $e) {
            // fall back to zone lookup
        }

        $zones = ShippingZoneResolver::getConfiguredZones();
        foreach ($zones as $zone) {
            if (($zone['id'] ?? '') === $method || ($zone['name'] ?? '') === $method) {
                $threshold = (float) ($zone['free_threshold'] ?? 0);
                if ($threshold > 0 && $subtotal >= $threshold) {
                    return 0.00;
                }
                return (float) ($zone['rate'] ?? 60.00);
            }
        }

        return str_contains($method, 'outside') ? 120.00 : 60.00;
    }

    /**
     * Public API endpoint to get active shipping zones and rates
     */
    public function shippingZones(): JsonResponse
    {
        $zones = ShippingZoneResolver::getConfiguredZones();
        return response()->json(['zones' => $zones]);
    }

    /**
     * Public API endpoint to get available payment methods
     */
    public function paymentMethods(PaymentManager $paymentManager): JsonResponse
    {
        return response()->json([
            'payment_methods' => $paymentManager->getPublicPaymentMethods(),
        ]);
    }

    /**
     * Customer self-service order cancellation.
     * Respects order state machine: only allowed when order is 'pending'.
     */
    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        $order = Order::where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        // Strict customer authorization
        if ((int) $order->user_id !== (int) $user->id && !$user->isStaffOrAdmin()) {
            return response()->json([
                'message' => 'Access Denied: You do not have authorization to cancel this order.',
            ], 403);
        }

        // State Machine validation: only pending orders can be cancelled directly by customer
        if ($order->order_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "Order #{$order->order_number} cannot be cancelled because it is already {$order->order_status}.",
            ], 422);
        }

        DB::transaction(function () use ($order, $user) {
            // 1. Restore product & variant inventory
            foreach ($order->items as $item) {
                if ($item->variant_id) {
                    $variant = ProductVariant::where('id', $item->variant_id)->lockForUpdate()->first();
                    if ($variant) {
                        $variant->increment('stock_quantity', $item->quantity);
                    }
                }
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $product->increment('stock_quantity', $item->quantity);
                }
            }

            // 2. Reverse Promotions & Coupons
            PromotionEngine::reverseOrderRedemptions($order, "Order cancelled by customer {$user->name}");

            // 3. Post Accounting Cancellation
            try {
                AccountingService::postOrderCancellation($order);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Accounting cancellation post deferred: " . $e->getMessage());
            }

            // 4. Record Timeline
            OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'cancelled',
                title: 'Order Cancelled by Customer',
                description: "Order #{$order->order_number} was cancelled by customer {$user->name}. Inventory and redemptions restored.",
                actorName: $user->name ?: 'Customer'
            );

            // 5. Update Status
            $order->update(['order_status' => 'cancelled']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Order successfully cancelled.',
            'order_status' => 'cancelled',
            'order' => $order->fresh(['items']),
        ]);
    }

    /**
     * Customer self-service order return request.
     * Allowed only for 'delivered' orders.
     */
    public function requestReturn(Request $request, string $orderNumber, OrderReturnService $returnService): JsonResponse
    {
        $user = $request->user();

        $order = Order::where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        // Strict customer authorization
        if ((int) $order->user_id !== (int) $user->id && !$user->isStaffOrAdmin()) {
            return response()->json([
                'message' => 'Access Denied: You do not have authorization to request a return for this order.',
            ], 403);
        }

        if ($order->order_status !== 'delivered') {
            return response()->json([
                'message' => 'Returns can only be requested for delivered orders.',
            ], 422);
        }

        $validated = $request->validate([
            'return_reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $orderReturn = $returnService->createReturn($order, [
            'return_type' => 'customer_return',
            'return_reason' => $validated['return_reason'],
            'notes' => $validated['notes'] ?? null,
        ], $user);

        return response()->json([
            'message' => 'Return request submitted successfully.',
            'return' => $orderReturn,
            'order' => $order->fresh(),
        ], 201);
    }
}

