<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\OrderTimelineService;
use App\Services\PromotionEngine;
use App\Services\StoreCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminOrderController extends Controller
{
    /**
     * Authoritative state transition matrix.
     * Prevents invalid or nonsensical status jumps (e.g., delivered -> pending, cancelled -> shipped).
     */
    public const VALID_TRANSITIONS = [
        'pending' => ['confirmed', 'processing', 'cancelled'],
        'confirmed' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled', 'refunded'],
        'delivered' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function counts(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $counts = [
            'all' => Order::count(),
            'pending' => Order::where('order_status', 'pending')->count(),
            'confirmed' => Order::where('order_status', 'confirmed')->count(),
            'processing' => Order::where('order_status', 'processing')->count(),
            'shipped' => Order::where('order_status', 'shipped')->count(),
            'delivered' => Order::where('order_status', 'delivered')->count(),
            'cancelled' => Order::where('order_status', 'cancelled')->count(),
            'refunded' => Order::where('order_status', 'refunded')->count(),
            'payment_pending' => Order::where('payment_status', 'pending')->count(),
            'payment_paid' => Order::where('payment_status', 'paid')->count(),
        ];

        return response()->json($counts);
    }

    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $query = Order::with([
            'items.product',
            'items.variant',
            'user',
            'cashierUser',
            'posRegisterSession.posRegister',
            'latestShipment',
            'shipments',
            'latestReturn',
        ]);

        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('order_source', $request->input('source'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('tracking_code', 'like', "%{$search}%")
                  ->orWhere('carrier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('order_status', $request->input('status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'all') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('payment_method') && $request->input('payment_method') !== 'all') {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('carrier')) {
            $query->where('carrier', 'like', "%" . $request->input('carrier') . "%");
        }

        if ($request->filled('min_total')) {
            $query->where('total_amount', '>=', (float) $request->input('min_total'));
        }

        if ($request->filled('max_total')) {
            $query->where('total_amount', '<=', (float) $request->input('max_total'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'order_number', 'created_at', 'total_amount', 'order_status', 'payment_status', 'customer_name'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest();
        }

        $perPage = (int) $request->input('per_page', 25);
        $orders = $query->paginate($perPage);

        // Enrich each order with automated fraud & RTO return-risk metrics
        $orders->getCollection()->transform(function ($order) {
            $phone = $order->customer_phone;
            $email = $order->customer_email;

            $historyQuery = Order::where('id', '!=', $order->id);
            if (!empty($phone)) {
                $historyQuery->where('customer_phone', $phone);
            } elseif (!empty($email)) {
                $historyQuery->where('customer_email', $email);
            } else {
                $historyQuery->whereRaw('1 = 0');
            }

            $pastOrders = $historyQuery->select('id', 'order_status', 'payment_status')->get();
            $totalPast = $pastOrders->count();
            $cancelledOrReturned = $pastOrders->whereIn('order_status', ['cancelled', 'returned', 'refunded'])->count();
            $deliveredPast = $pastOrders->where('order_status', 'delivered')->count();

            // Calculate Fraud / Return Risk
            if ($cancelledOrReturned >= 2 || ($totalPast > 0 && ($cancelledOrReturned / $totalPast) >= 0.5)) {
                $riskLevel = 'high';
                $fraudScore = 85;
            } elseif ($order->payment_method === 'cash_on_delivery' && $totalPast === 0) {
                $riskLevel = 'moderate';
                $fraudScore = 35;
            } elseif ($deliveredPast >= 2 && $cancelledOrReturned === 0) {
                $riskLevel = 'low';
                $fraudScore = 5;
            } else {
                $riskLevel = 'low';
                $fraudScore = 15;
            }

            $order->risk_level = $riskLevel;
            $order->fraud_score = $fraudScore;
            $order->customer_order_count = $totalPast + 1;
            $order->customer_return_count = $cancelledOrReturned;

            return $order;
        });

        return response()->json($orders);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $order = Order::with([
            'items.product',
            'items.variant',
            'user',
            'shipments',
            'latestShipment',
            'returns',
            'latestReturn',
            'timelineEvents',
            'payments',
        ])->findOrFail($id);

        return response()->json($order);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'user_id' => 'nullable|exists:users,id',
            'shipping_address' => 'required|array',
            'shipping_address.full_name' => 'required|string',
            'shipping_address.address_line1' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.state' => 'nullable|string',
            'shipping_address.postal_code' => 'required|string',
            'shipping_address.country' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.price_override_reason' => 'nullable|string|max:500',
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'payment_method' => 'required|string',
            'order_status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'shipping_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $subtotal = 0;
        $orderItemsData = [];
        $overriddenItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::with(['primaryImage', 'images'])->findOrFail($item['product_id']);
            $cataloguePrice = (float) $product->price;
            $variant = null;
            if (!empty($item['variant_id'])) {
                $variant = ProductVariant::where('id', $item['variant_id'])->where('product_id', $product->id)->firstOrFail();
                if (!$variant->is_active) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["The selected variant '{$variant->name}' is inactive and cannot be ordered."],
                    ]);
                }
                $cataloguePrice = (float) $variant->effective_price;
            }

            $submittedPrice = isset($item['unit_price']) ? round((float) $item['unit_price'], 2) : null;
            $unitPrice = $cataloguePrice;
            $isOverridden = false;
            $overrideReason = null;

            if ($submittedPrice !== null && abs($submittedPrice - $cataloguePrice) > 0.001) {
                // Check permission: user must have 'orders.price_override' or be Super Admin / Admin
                $user = $request->user();
                if (!$user->isSuperAdmin() && !$user->isAdmin() && !$user->hasPermission('orders.price_override')) {
                    abort(403, 'Unauthorized price override. Missing required permission: orders.price_override');
                }

                // Zero-price protection
                if ($submittedPrice <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["Zero-dollar or negative price overrides are strictly prohibited without administrative authorization."],
                    ]);
                }

                // Mandatory reason (minimum 5 characters)
                $reason = trim($item['price_override_reason'] ?? ($request->input('price_override_reason') ?? ''));
                if (strlen($reason) < 5) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["A valid price override reason (minimum 5 characters) is required when modifying catalogue prices."],
                    ]);
                }

                $isOverridden = true;
                $overrideReason = $reason;
                $unitPrice = $submittedPrice;
                $overriddenItems[] = [
                    'product_name' => $product->name,
                    'catalogue_price' => $cataloguePrice,
                    'override_price' => $unitPrice,
                    'reason' => $overrideReason,
                ];
            }

            $quantity = (int) $item['quantity'];
            $totalPrice = round($unitPrice * $quantity, 2);
            $subtotal += $totalPrice;

            // Reduce stock
            $product->decrement('stock_quantity', $quantity);
            if ($variant) {
                $variant->decrement('stock_quantity', $quantity);
            }

            $orderItemsData[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => $variant ? $variant->sku : $product->sku,
                'product_image' => $product->primaryImage?->image_url ?? $product->images?->first()?->image_url,
                'variant_name' => $variant?->name,
                'variant_attributes' => $variant?->variant_attributes,
                'unit_price' => $unitPrice,
                'original_unit_price' => $cataloguePrice,
                'is_price_overridden' => $isOverridden,
                'override_reason' => $overrideReason,
                'quantity' => $quantity,
                'total_price' => $totalPrice,
            ];
        }

        $shipping = (float) ($validated['shipping_amount'] ?? 0);
        $tax = (float) ($validated['tax_amount'] ?? (Setting::isVatEnabled() ? round($subtotal * (Setting::getVatRate() / 100), 2) : 0.00));
        $discount = (float) ($validated['discount_amount'] ?? 0);
        $totalAmount = max(0, $subtotal + $shipping + $tax - $discount);

        $orderNumber = 'ORD-' . strtoupper(Str::random(8));

        $order = Order::create([
            'user_id' => $validated['user_id'] ?? null,
            'order_number' => $orderNumber,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'shipping_address' => $validated['shipping_address'],
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'shipping_amount' => $shipping,
            'discount_amount' => $discount,
            'total_amount' => $totalAmount,
            'payment_status' => $validated['payment_status'],
            'payment_method' => $validated['payment_method'],
            'order_status' => $validated['order_status'],
            'carrier' => $validated['carrier'] ?? null,
            'tracking_code' => $validated['tracking_code'] ?? null,
            'notes' => $validated['notes'] ?? 'Order created via Admin Console.',
            'ip_address' => $request->ip(),
            'shipped_at' => $validated['order_status'] === 'shipped' ? now() : null,
            'delivered_at' => $validated['order_status'] === 'delivered' ? now() : null,
        ]);

        foreach ($orderItemsData as $itemData) {
            $order->items()->create($itemData);
        }

        // If order was marked as paid on creation, record initial payment in ledger
        if ($order->payment_status === 'paid' && $order->total_amount > 0) {
            $order->payments()->create([
                'payment_number' => \App\Models\OrderPayment::generatePaymentNumber(),
                'payment_method' => $order->payment_method ?: 'cash',
                'provider' => 'manual',
                'amount' => $order->total_amount,
                'currency' => 'BDT',
                'status' => 'completed',
                'type' => 'full_payment',
                'collected_at' => now(),
                'notes' => 'Initial payment upon admin order creation',
                'created_by_user_id' => $request->user()?->id,
            ]);
        }

        if (!empty($overriddenItems)) {
            AuditLog::log(
                $request->user(),
                'order.price_override',
                'Order',
                $order->id,
                "Admin {$request->user()->name} applied price override(s) on Order #{$order->order_number}.",
                null,
                ['overrides' => $overriddenItems]
            );
        }

        AuditLog::log(
            $request->user(),
            'order.created',
            'Order',
            $order->id,
            "Created order #{$order->order_number} for customer {$order->customer_name} (Total: \${$order->total_amount}).",
            null,
            $order->toArray()
        );

        return response()->json([
            'message' => "Order {$order->order_number} created successfully.",
            'order' => $order->load(['items.product', 'user']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with('items')->findOrFail($id);
        $oldValues = $order->toArray();
        $oldStatus = $order->order_status;
        $oldPaymentStatus = $order->payment_status;

        $validated = $request->validate([
            'customer_name' => 'sometimes|required|string|max:255',
            'customer_email' => 'sometimes|required|email|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'order_status' => 'sometimes|required|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|required|in:pending,paid,failed,refunded',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'shipping_address' => 'nullable|array',
            'billing_address' => 'nullable|array',
        ]);

        // State machine transition validation
        if (isset($validated['order_status']) && $validated['order_status'] !== $oldStatus) {
            $newStatus = $validated['order_status'];
            $allowedNext = self::VALID_TRANSITIONS[$oldStatus] ?? [];
            if (!in_array($newStatus, $allowedNext)) {
                return response()->json([
                    'message' => "Invalid order status transition from '{$oldStatus}' to '{$newStatus}'.",
                    'errors' => [
                        'order_status' => ["Cannot transition order from '{$oldStatus}' to '{$newStatus}'."]
                    ]
                ], 422);
            }

            if ($newStatus === 'shipped' && !$order->shipped_at) {
                $validated['shipped_at'] = now();
            }
            if ($newStatus === 'delivered' && !$order->delivered_at) {
                $validated['delivered_at'] = now();
            }

            if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                $this->restoreOrderInventory($order, 'cancellation');
                AccountingService::postOrderCancellation($order);
                PromotionEngine::reverseOrderRedemptions($order, "Order cancelled by {$request->user()->name}");
                OrderTimelineService::recordEvent(
                    order: $order,
                    eventType: 'cancelled',
                    title: 'Order Cancelled',
                    description: "Order marked as cancelled by {$request->user()->name}. Inventory, promotions, and accounting reversed.",
                    actorName: $request->user()->name,
                    iconType: 'x'
                );
            }
        }

        // Payment status rule validation
        if (isset($validated['payment_status']) && $validated['payment_status'] !== $oldPaymentStatus) {
            $newPaymentStatus = $validated['payment_status'];
            if ($newPaymentStatus === 'paid') {
                $isCod = in_array(strtolower($order->payment_method), ['cash_on_delivery', 'cod', 'cash', 'pos_cash']);
                if (!$isCod) {
                    return response()->json([
                        'message' => 'Online payment cannot be marked as paid without verified provider confirmation.',
                        'errors' => [
                            'payment_status' => ['Online payment orders require verified gateway provider confirmation.']
                        ]
                    ], 422);
                }

                $order->recordPayment(
                    amount: (float) $order->total_amount,
                    paymentMethod: 'cash_on_delivery',
                    type: 'collection',
                    provider: 'manual',
                    transactionId: null,
                    notes: "Cash on delivery marked collected by " . $request->user()->name,
                    userId: $request->user()->id,
                    status: 'completed'
                );
                unset($validated['payment_status']);
            }
        }

        $order->fill($validated);
        $wasDirty = $order->isDirty();
        $order->save();

        if ($wasDirty) {
            AuditLog::log(
                $request->user(),
                'order.updated',
                'Order',
                $order->id,
                "Updated details for order #{$order->order_number}.",
                $oldValues,
                $order->toArray()
            );
        }

        return response()->json([
            'message' => "Order #{$order->order_number} updated successfully.",
            'order' => $order->fresh(['items.product', 'user', 'payments', 'shipments']),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with('items')->findOrFail($id);
        $oldStatus = $order->order_status;
        $oldPaymentStatus = $order->payment_status;

        $validated = $request->validate([
            'order_status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,refunded',
            'carrier' => 'nullable|string|max:100',
            'tracking_code' => 'nullable|string|max:100',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
            'notes' => 'nullable|string',
        ]);

        $newStatus = $validated['order_status'];

        // Validate state transition if status is changing
        if ($newStatus !== $oldStatus) {
            $allowedNext = self::VALID_TRANSITIONS[$oldStatus] ?? [];
            if (!in_array($newStatus, $allowedNext)) {
                return response()->json([
                    'message' => "Invalid order status transition from '{$oldStatus}' to '{$newStatus}'.",
                    'errors' => [
                        'order_status' => ["Cannot transition order from '{$oldStatus}' to '{$newStatus}'."]
                    ]
                ], 422);
            }
        }

        // Validate payment status changes if passed
        if (isset($validated['payment_status']) && $validated['payment_status'] !== $oldPaymentStatus) {
            $newPaymentStatus = $validated['payment_status'];

            if ($newPaymentStatus === 'paid') {
                $isCod = in_array(strtolower($order->payment_method), ['cash_on_delivery', 'cod', 'cash', 'pos_cash']);
                if (!$isCod) {
                    return response()->json([
                        'message' => 'Online payment cannot be marked as paid without verified provider confirmation.',
                        'errors' => [
                            'payment_status' => ['Online payment orders require verified gateway provider confirmation.']
                        ]
                    ], 422);
                }

                // For COD, record payment ledger entry
                $order->recordPayment(
                    amount: (float) $order->total_amount,
                    paymentMethod: 'cash_on_delivery',
                    type: 'collection',
                    provider: 'manual',
                    transactionId: null,
                    notes: "Cash on delivery marked collected by " . $request->user()->name,
                    userId: $request->user()->id,
                    status: 'completed'
                );
            } else {
                $order->update(['payment_status' => $newPaymentStatus]);
            }
        }

        // Handle timestamps
        if ($newStatus === 'shipped' && !$order->shipped_at) {
            $order->shipped_at = now();
        }
        if ($newStatus === 'delivered' && !$order->delivered_at) {
            $order->delivered_at = now();
        }

        // If transitioning to cancelled from non-delivered, restore variant stock, reverse accounting & promotions
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            $this->restoreOrderInventory($order, 'cancellation');
            AccountingService::postOrderCancellation($order);
            PromotionEngine::reverseOrderRedemptions($order, "Order cancelled by {$request->user()->name}");
            OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'cancelled',
                title: 'Order Cancelled',
                description: "Order marked as cancelled by {$request->user()->name}. Inventory, promotions, and accounting reversed.",
                actorName: $request->user()->name,
                iconType: 'x'
            );
        } elseif ($newStatus === 'confirmed' && $oldStatus !== 'confirmed') {
            OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'confirmed',
                title: 'Order Confirmed',
                description: "Order confirmed by {$request->user()->name}. Prepared for fulfillment.",
                actorName: $request->user()->name,
                iconType: 'check'
            );
        } elseif ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
            OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'delivered',
                title: 'Order Delivered',
                description: "Order marked delivered by {$request->user()->name}.",
                actorName: $request->user()->name,
                iconType: 'check'
            );
        }

        $order->update([
            'order_status' => $newStatus,
            'carrier' => $validated['carrier'] ?? $order->carrier,
            'tracking_code' => $validated['tracking_code'] ?? $order->tracking_code,
            'notes' => $validated['notes'] ?? $order->notes,
        ]);

        AuditLog::log(
            $request->user(),
            'order.status_updated',
            'Order',
            $order->id,
            "Order {$order->order_number} fulfillment status updated from '{$oldStatus}' to '{$newStatus}'.",
            ['status' => $oldStatus, 'payment_status' => $oldPaymentStatus],
            ['status' => $newStatus, 'payment_status' => $order->payment_status, 'carrier' => $order->carrier, 'tracking_code' => $order->tracking_code]
        );

        return response()->json([
            'message' => "Order {$order->order_number} status updated to '{$newStatus}'.",
            'order' => $order->fresh(['items', 'user', 'payments', 'shipments']),
        ]);
    }

    /**
     * Record cash on delivery collection or manual payment.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $isCod = in_array(strtolower($order->payment_method), ['cash_on_delivery', 'cod', 'cash', 'pos_cash']);
        if (!$isCod) {
            return response()->json([
                'message' => 'Online payment cannot be manually marked as paid without gateway verification.',
                'errors' => [
                    'payment_method' => ['Online payment orders require verified gateway provider confirmation.']
                ]
            ], 422);
        }

        $amount = (float) ($validated['amount'] ?? $order->outstanding_balance);
        if ($amount <= 0) {
            $amount = (float) $order->total_amount;
        }

        $payment = $order->recordPayment(
            amount: $amount,
            paymentMethod: 'cash_on_delivery',
            type: 'collection',
            provider: 'manual',
            transactionId: null,
            notes: $validated['notes'] ?? ("COD collection recorded by " . $request->user()->name),
            userId: $request->user()->id,
            status: 'completed'
        );

        AuditLog::log(
            $request->user(),
            'order.payment_recorded',
            'Order',
            $order->id,
            "Recorded COD payment of ৳{$amount} for Order #{$order->order_number}.",
            null,
            $payment->toArray()
        );

        OrderTimelineService::recordEvent(
            order: $order,
            eventType: 'accounting_adjusted',
            title: "Payment Collected (৳" . number_format($amount, 2) . ")",
            description: "Cash on delivery payment of ৳" . number_format($amount, 2) . " received and verified by {$request->user()->name}.",
            actorName: $request->user()->name,
            iconType: 'dollar'
        );

        return response()->json([
            'message' => "Payment of ৳{$amount} recorded successfully for Order #{$order->order_number}.",
            'payment' => $payment,
            'order' => $order->fresh(['items', 'user', 'payments', 'shipments']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with('items')->findOrFail($id);
        $orderNumber = $order->order_number;
        $oldValues = $order->toArray();

        DB::transaction(function () use ($order, $request, $id, $orderNumber, $oldValues) {
            // If order was not already delivered/cancelled, restore stock, reverse accounting & promotions
            if (!in_array($order->order_status, ['cancelled', 'refunded', 'returned', 'delivered'])) {
                $this->restoreOrderInventory($order, 'archival');
                AccountingService::postOrderCancellation($order);
                PromotionEngine::reverseOrderRedemptions($order, "Order archived/deleted by {$request->user()->name}");
            }

            OrderTimelineService::recordEvent(
                order: $order,
                eventType: 'cancelled',
                title: 'Order Archived / Soft-Deleted',
                description: "Order was safely archived by {$request->user()->name}. Financial history preserved.",
                actorName: $request->user()->name,
                iconType: 'x'
            );

            $order->items()->delete();
            $order->delete(); // Soft delete preserves foreign keys and journal entries

            AuditLog::log(
                $request->user(),
                'order.deleted',
                'Order',
                $id,
                "Archived (soft-deleted) order #{$orderNumber}.",
                $oldValues,
                null
            );
        });

        return response()->json([
            'message' => "Order #{$orderNumber} deleted successfully.",
        ]);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with('items')->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'restock' => 'boolean',
            'amount' => 'nullable|numeric|min:0.01',
            'refund_method' => 'nullable|string|in:cash,bank_transfer,bkash,nagad,store_credit',
        ]);

        try {
            $updatedOrder = app(\App\Services\OrderReturnService::class)->refundDirectOrder(
                $order,
                $validated,
                $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Order {$order->order_number} successfully refunded.",
            'order' => $updatedOrder,
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $count = 0;

        DB::transaction(function () use ($validated, $request, &$count) {
            $orders = Order::with('items')->whereIn('id', $validated['ids'])->get();

            foreach ($orders as $order) {
                $orderNumber = $order->order_number;
                $oldValues = $order->toArray();

                if (!in_array($order->order_status, ['cancelled', 'refunded', 'returned', 'delivered'])) {
                    $this->restoreOrderInventory($order, 'bulk_archival');
                    AccountingService::postOrderCancellation($order);
                    PromotionEngine::reverseOrderRedemptions($order, "Bulk archived by {$request->user()->name}");
                }

                OrderTimelineService::recordEvent(
                    order: $order,
                    eventType: 'cancelled',
                    title: 'Order Archived / Soft-Deleted',
                    description: "Order was safely archived by {$request->user()->name}.",
                    actorName: $request->user()->name,
                    iconType: 'x'
                );

                $order->items()->delete();
                $order->delete(); // Soft delete
                $count++;

                AuditLog::log(
                    $request->user(),
                    'order.deleted',
                    'Order',
                    $order->id,
                    "Bulk archived order #{$orderNumber}.",
                    $oldValues,
                    null
                );
            }
        });

        return response()->json([
            'message' => "Successfully deleted {$count} order(s).",
            'deleted_count' => $count,
        ]);
    }

    /**
     * Helper to safely and idempotently restore variant and product inventory with audit movements and FIFO layer recovery.
     */
    protected function restoreOrderInventory(Order $order, string $reason = 'cancellation'): void
    {
        app(\App\Services\OrderReturnService::class)->restoreOrderInventory($order, $reason, auth()->user());
    }

    /**
     * Get available couriers and pre-filled logistics params for an order
     */
    public function getCourierOptions(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with(['items.product', 'shipments'])->findOrFail($id);
        $courierManager = app(\App\Services\Courier\CourierManager::class);
        $providers = $courierManager->getAvailableProviders();

        $activeShipment = $order->shipments()
            ->whereNotIn('status', ['cancelled', 'delivery_failed'])
            ->first();

        // Calculate approximate order weight based on item quantities
        $itemCount = $order->items->sum('quantity');
        $suggestedWeight = max(0.5, round($itemCount * 0.4, 2));

        $codAmount = $order->payment_status === 'paid' ? 0.00 : (float) $order->total_amount;

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'payment_status' => $order->payment_status,
            'suggested_cod_amount' => $codAmount,
            'suggested_weight' => $suggestedWeight,
            'default_provider' => $courierManager->getDefaultProvider(),
            'providers' => $providers,
            'active_shipment' => $activeShipment,
        ]);
    }

    /**
     * Book parcel with courier
     */
    public function bookShipment(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::with('items')->findOrFail($id);

        $validated = $request->validate([
            'provider' => 'required|string|in:steadfast,pathao,redx',
            'weight' => 'nullable|numeric|min:0.1',
            'cod_amount' => 'nullable|numeric|min:0',
            'pickup_store_id' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'delivery_area' => 'nullable|string|max:150',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_phone' => 'nullable|string|max:30',
            'recipient_address' => 'nullable|string',
            'recipient_city_id' => 'nullable|integer',
            'recipient_zone_id' => 'nullable|integer',
            'recipient_area_id' => 'nullable|integer',
        ]);

        try {
            $courierManager = app(\App\Services\Courier\CourierManager::class);
            $shipment = $courierManager->bookShipment($order, $validated);

            return response()->json([
                'message' => "Shipment successfully booked with {$shipment->provider}. Consignment: {$shipment->consignment_id}",
                'shipment' => $shipment,
                'order' => $order->fresh(['items', 'shipments', 'latestShipment']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Poll on-demand live status for a shipment
     */
    public function trackShipment(Request $request, int $id, int $shipmentId): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $order = Order::findOrFail($id);
        $shipment = Shipment::where('order_id', $order->id)->findOrFail($shipmentId);

        $courierManager = app(\App\Services\Courier\CourierManager::class);
        $updatedShipment = $courierManager->syncShipmentStatus($shipment);

        return response()->json([
            'message' => "Shipment status refreshed: {$updatedShipment->status}",
            'shipment' => $updatedShipment,
            'order' => $order->fresh(['shipments', 'latestShipment']),
        ]);
    }

    /**
     * Cancel an active consignment with courier
     */
    public function cancelShipment(Request $request, int $id, int $shipmentId): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $order = Order::findOrFail($id);
        $shipment = Shipment::where('order_id', $order->id)->findOrFail($shipmentId);

        if (!$shipment->canBeCancelled()) {
            return response()->json([
                'message' => "Shipment cannot be cancelled in status '{$shipment->status}'.",
            ], 422);
        }

        $courierManager = app(\App\Services\Courier\CourierManager::class);
        $courierManager->driver($shipment->provider)->cancelShipment($shipment->consignment_id);

        $shipment->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        \App\Models\AuditLog::log(
            $request->user(),
            'courier.cancelled',
            'Shipment',
            $shipment->id,
            "Cancelled consignment #{$shipment->consignment_id} ({$shipment->provider}) for Order #{$order->order_number}."
        );

        return response()->json([
            'message' => "Shipment #{$shipment->consignment_id} cancelled.",
            'shipment' => $shipment,
            'order' => $order->fresh(['shipments', 'latestShipment']),
        ]);
    }

    /**
     * Get printable dispatch label data
     */
    public function printShippingLabel(Request $request, int $id, int $shipmentId): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $order = Order::with('items.product')->findOrFail($id);
        $shipment = Shipment::where('order_id', $order->id)->findOrFail($shipmentId);

        return response()->json([
            'label' => [
                'order_number' => $order->order_number,
                'created_at' => $order->created_at->format('d M Y, h:i A'),
                'provider' => ucfirst($shipment->provider),
                'consignment_id' => $shipment->consignment_id,
                'tracking_code' => $shipment->tracking_code,
                'tracking_url' => $shipment->tracking_url,
                'recipient_name' => $shipment->recipient_name,
                'recipient_phone' => $shipment->recipient_phone,
                'recipient_address' => $shipment->recipient_address,
                'delivery_area' => $shipment->delivery_area,
                'cod_amount' => $shipment->cod_amount,
                'weight' => $shipment->weight . ' kg',
                'notes' => $shipment->notes,
                'items' => $order->items->map(function ($item) {
                    return [
                        'name' => $item->product_name ?: $item->product?->name,
                        'variant' => $item->variant_name,
                        'quantity' => $item->quantity,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Get order lifecycle timeline events
     */
    public function getTimeline(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $order = Order::findOrFail($id);
        $timeline = OrderTimelineService::getTimeline($order);

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Get Pathao courier cities for booking dropdown
     */
    public function getPathaoCities(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        try {
            $courierManager = app(\App\Services\Courier\CourierManager::class);
            $cities = $courierManager->driver('pathao')->getCities();

            return response()->json(['cities' => $cities]);
        } catch (\Throwable $e) {
            return response()->json(['cities' => [], 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Get Pathao courier zones for a city
     */
    public function getPathaoZones(Request $request, int $cityId): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        try {
            $courierManager = app(\App\Services\Courier\CourierManager::class);
            $zones = $courierManager->driver('pathao')->getZones($cityId);

            return response()->json(['zones' => $zones]);
        } catch (\Throwable $e) {
            return response()->json(['zones' => [], 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Get Pathao courier areas for a zone
     */
    public function getPathaoAreas(Request $request, int $zoneId): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        try {
            $courierManager = app(\App\Services\Courier\CourierManager::class);
            $areas = $courierManager->driver('pathao')->getAreas($zoneId);

            return response()->json(['areas' => $areas]);
        } catch (\Throwable $e) {
            return response()->json(['areas' => [], 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Calculate courier delivery charge and COD estimate dynamically
     */
    public function calculateCourierPrice(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'provider' => 'required|string|in:pathao,steadfast,redx',
            'recipient_city_id' => 'required|integer',
            'recipient_zone_id' => 'required|integer',
            'recipient_area_id' => 'nullable|integer',
            'weight' => 'required|numeric|min:0.05|max:50',
            'pickup_store_id' => 'nullable|string',
            'delivery_type' => 'nullable|integer|in:48,12',
        ]);

        try {
            $courierManager = app(\App\Services\Courier\CourierManager::class);
            $result = $courierManager->calculateDeliveryPrice($validated['provider'], $validated);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to calculate courier delivery price.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'pricing' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Price calculation error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bulk printable thermal shipping labels for multiple orders (4x6 format)
     */
    public function bulkShippingLabels(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.view', 'orders.manage');

        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::with(['items.product', 'latestShipment'])->whereIn('id', $validated['order_ids'])->get();
        $appName = config('app.name', 'INHALIQ');

        $labels = $orders->map(function ($order) use ($appName) {
            $shipment = $order->latestShipment;
            $shipAddress = $order->shipping_address ?? [];
            $addressStr = is_array($shipAddress) ? ($shipAddress['address_line1'] ?? $shipAddress['address'] ?? 'Customer Address') : 'Customer Address';
            $cityStr = is_array($shipAddress) ? ($shipAddress['city'] ?? 'Dhaka') : 'Dhaka';

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'store_name' => $appName,
                'sender_phone' => config('store.phone', '01700000000'),
                'courier_name' => $shipment ? ucfirst($shipment->provider) : ($order->carrier ?: 'Steadfast Courier'),
                'delivery_type' => 'Standard Express (COD)',
                'consignment_id' => $shipment ? ($shipment->consignment_id ?: $shipment->tracking_code) : $order->order_number,
                'tracking_code' => $shipment ? $shipment->tracking_code : ($order->tracking_code ?: 'Pending'),
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone ?: 'N/A',
                'customer_address' => $addressStr,
                'customer_city' => $cityStr,
                'weight' => ($shipment ? $shipment->weight : 0.5) . ' kg',
                'cod_amount' => $order->payment_status === 'paid' ? 0 : (float) $order->total_amount,
                'notes' => $order->notes ?: 'Handle with care. Inspect before delivery.',
                'date' => $order->created_at->format('d M Y'),
                'items' => $order->items->map(function ($item) {
                    return [
                        'name' => $item->product_name ?: $item->product?->name,
                        'variant' => $item->variant_name,
                        'quantity' => $item->quantity,
                    ];
                }),
            ];
        });

        return response()->json([
            'labels' => $labels,
            'count' => $labels->count(),
        ]);
    }

    /**
     * Bulk Courier Booking for selected orders
     */
    public function bulkBookCourier(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'orders.manage');

        $validated = $request->validate([
            'order_ids' => 'required_without:orders|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
            'orders' => 'nullable|array|min:1',
            'orders.*.id' => 'required_with:orders|integer|exists:orders,id',
            'orders.*.recipient_city_id' => 'nullable|integer',
            'orders.*.recipient_zone_id' => 'nullable|integer',
            'orders.*.recipient_area_id' => 'nullable|integer',
            'orders.*.recipient_address' => 'nullable|string',
            'orders.*.recipient_phone' => 'nullable|string',
            'orders.*.cod_amount' => 'nullable|numeric|min:0',
            'orders.*.weight' => 'nullable|numeric|min:0.05',
            'orders.*.notes' => 'nullable|string|max:500',
            'provider' => 'nullable|string|in:steadfast,pathao,redx',
            'pickup_store_id' => 'nullable|string',
        ]);

        $provider = $validated['provider'] ?? 'steadfast';
        $pickupStoreId = $validated['pickup_store_id'] ?? null;

        // Build target order IDs and per-order parameter overrides
        $targetIds = [];
        $overridesByOrderId = [];

        if (!empty($validated['orders'])) {
            foreach ($validated['orders'] as $item) {
                $oId = (int) $item['id'];
                $targetIds[] = $oId;
                $overridesByOrderId[$oId] = $item;
            }
        } elseif (!empty($validated['order_ids'])) {
            $targetIds = array_map('intval', $validated['order_ids']);
        }

        $orders = Order::with(['latestShipment', 'items.product', 'shipments'])->whereIn('id', $targetIds)->get();

        $courierManager = app(\App\Services\Courier\CourierManager::class);
        $successCount = 0;
        $failedCount = 0;
        $messages = [];
        $results = [];

        foreach ($orders as $order) {
            // Check terminal statuses
            if ($order->order_status === 'cancelled' || $order->order_status === 'delivered') {
                $failedCount++;
                $msg = "Order #{$order->order_number} skipped (Status: {$order->order_status})";
                $messages[] = $msg;
                $results[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'success' => false,
                    'message' => $msg,
                ];
                continue;
            }

            // Check if active shipment exists
            $existingActive = $order->shipments
                ->whereNotIn('status', ['cancelled', 'delivery_failed'])
                ->first();

            if ($existingActive) {
                $failedCount++;
                $msg = "Order #{$order->order_number} already has active consignment (#{$existingActive->consignment_id}) with {$existingActive->provider}.";
                $messages[] = $msg;
                $results[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'success' => false,
                    'message' => $msg,
                    'consignment_id' => $existingActive->consignment_id,
                    'tracking_code' => $existingActive->tracking_code,
                ];
                continue;
            }

            try {
                $orderOverrides = $overridesByOrderId[$order->id] ?? [];
                $orderOverrides['provider'] = $provider;

                if (!empty($pickupStoreId)) {
                    $orderOverrides['pickup_store_id'] = $pickupStoreId;
                }

                // Pathao requires city & zone
                if ($provider === 'pathao') {
                    $cityId = $orderOverrides['recipient_city_id'] ?? $order->shipping_city_id;
                    $zoneId = $orderOverrides['recipient_zone_id'] ?? $order->shipping_zone_id;

                    if (empty($cityId) || empty($zoneId)) {
                        $failedCount++;
                        $msg = "Order #{$order->order_number}: Pathao requires explicitly selected City and Zone.";
                        $messages[] = $msg;
                        $results[] = [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'success' => false,
                            'message' => $msg,
                        ];
                        continue;
                    }

                    $orderOverrides['recipient_city_id'] = (int) $cityId;
                    $orderOverrides['recipient_zone_id'] = (int) $zoneId;
                    if (!empty($orderOverrides['recipient_area_id']) || !empty($order->shipping_area_id)) {
                        $orderOverrides['recipient_area_id'] = (int) ($orderOverrides['recipient_area_id'] ?? $order->shipping_area_id);
                    }
                }

                // Execute booking via standard atomic pipeline
                $shipment = $courierManager->bookShipment($order, $orderOverrides);

                // Dispatch WhatsApp notification if enabled
                try {
                    \App\Services\CustomerNotificationService::orderDispatched($order, $shipment);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("WhatsApp dispatch notice failed for Order #{$order->order_number}: " . $e->getMessage());
                }

                $successCount++;
                $results[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'success' => true,
                    'consignment_id' => $shipment->consignment_id,
                    'tracking_code' => $shipment->tracking_code,
                    'tracking_url' => $shipment->tracking_url,
                    'provider' => $shipment->provider,
                    'status' => $shipment->status,
                    'cod_amount' => $shipment->cod_amount,
                ];
            } catch (\Throwable $e) {
                $failedCount++;
                $errorMsg = "Order #{$order->order_number} failed: " . $e->getMessage();
                $messages[] = $errorMsg;
                $results[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        AuditLog::log(
            $request->user(),
            'courier.bulk_booked',
            'Order',
            null,
            "Bulk booked {$successCount} orders with {$provider}.",
            null,
            [
                'provider' => $provider,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'orders_processed' => count($targetIds),
            ]
        );

        return response()->json([
            'success' => $successCount > 0,
            'message' => "Bulk courier booking finished. {$successCount} succeeded, {$failedCount} failed.",
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
            'details' => $messages,
        ]);
    }
}

