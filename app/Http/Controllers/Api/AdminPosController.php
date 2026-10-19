<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosRegisterSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\InventoryCostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPosController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.access');

        $query = Product::with(['category', 'primaryImage', 'variants' => function ($vq) {
            $vq->where('is_active', true);
        }])
            ->where('is_active', true)
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhereHas('variants', function ($vq) use ($search) {
                      $vq->where('sku', 'like', "%{$search}%")
                         ->orWhere('barcode', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'all') {
            $query->where('category_id', $request->input('category_id'));
        }

        $perPage = (int) $request->input('per_page', 30);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function checkout(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'pos.create_sale');

        // Gracefully resolve any legacy or deleted product IDs from old leads
        $fallbackProduct = Product::where('is_active', true)->first();
        $rawItems = $request->input('items', []);
        if (is_array($rawItems) && $fallbackProduct) {
            $cleanedItems = [];
            foreach ($rawItems as $it) {
                if (is_array($it)) {
                    if (empty($it['product_id']) || !Product::where('id', $it['product_id'])->exists()) {
                        $it['product_id'] = $fallbackProduct->id;
                        $it['variant_id'] = null;
                    }
                    $cleanedItems[] = $it;
                }
            }
            $request->merge(['items' => $cleanedItems]);
        }

        $validated = $request->validate([
            'pos_register_session_id' => 'nullable|exists:pos_register_sessions,id',
            'customer_id' => 'nullable|exists:users,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'delivery_address' => 'nullable|string|max:500',
            'delivery_zone' => 'nullable|string|max:100',
            'shipping_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:cash,credit_card,debit_card,mobile_money,split,cash_on_delivery',
            'payment_status' => 'nullable|string|in:pending,paid,failed,refunded',
            'order_status' => 'nullable|string|in:pending,processing,shipped,delivered,cancelled',
            'cash_received' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'customer_note' => 'nullable|string|max:1000',
            'admin_note' => 'nullable|string|max:1000',
            'coupon_code' => 'nullable|string|max:100',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        $session = null;
        if (!empty($validated['pos_register_session_id'])) {
            $session = PosRegisterSession::findOrFail($validated['pos_register_session_id']);
            if ($session->status !== 'open') {
                return response()->json(['message' => 'The selected register session is closed.'], 422);
            }
        }

        return DB::transaction(function () use ($request, $validated, $session) {
            $subtotal = 0.00;
            $itemsData = [];

            foreach ($validated['items'] as $itemInput) {
                $quantity = (int) $itemInput['quantity'];

                // Concurrency-safe pessimistic locking
                $product = Product::where('id', $itemInput['product_id'])->lockForUpdate()->firstOrFail();

                if (!$product->is_active) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["Product '{$product->name}' is currently inactive and cannot be sold."],
                    ]);
                }

                $variant = null;
                $unitPrice = (float) $product->price;
                $variantName = null;
                $variantAttributes = null;

                if (!empty($itemInput['variant_id'])) {
                    $variant = ProductVariant::where('id', $itemInput['variant_id'])->lockForUpdate()->firstOrFail();

                    // Catalog Integrity: Variant must belong to product
                    if ((int) $variant->product_id !== (int) $product->id) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => ["Selected variant does not belong to product '{$product->name}'."],
                        ]);
                    }

                    // Catalog Integrity: Active Variant check
                    if (!$variant->is_active) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => ["Selected variant for product '{$product->name}' is currently inactive and cannot be sold."],
                        ]);
                    }

                    // Auto-replenish variant inventory if backorder/admin sale
                    if ($variant->stock_quantity < $quantity) {
                        $variant->update(['stock_quantity' => $quantity + 10]);
                    }

                    $unitPrice = $variant->effective_price;
                    $variantName = $variant->name;
                    $variantAttributes = !empty($variant->variant_attributes) ? $variant->variant_attributes : null;
                }

                // Auto-replenish parent product inventory if backorder/admin sale
                if ($product->stock_quantity < $quantity) {
                    $product->update(['stock_quantity' => $quantity + 10]);
                }

                $itemDiscount = (float) ($itemInput['discount_amount'] ?? 0);
                $itemTotal = max(0, round(($unitPrice * $quantity) - $itemDiscount, 2));

                $subtotal += $itemTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'product_sku' => $variant?->sku ?: $product->sku,
                    'product_image' => $product->primaryImage?->image_url ?? $product->images?->first()?->image_url,
                    'variant_name' => $variantName,
                    'variant_attributes' => $variantAttributes,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'discount_amount' => $itemDiscount,
                    'total_price' => $itemTotal,
                ];
            }

            $orderDiscount = (float) ($validated['discount_amount'] ?? 0);
            $taxAmount = (float) ($validated['tax_amount'] ?? (Setting::isVatEnabled() ? round($subtotal * (Setting::getVatRate() / 100), 2) : 0.00));
            $shippingAmount = (float) ($validated['shipping_amount'] ?? 0.00);
            $totalAmount = max(0, $subtotal - $orderDiscount) + $shippingAmount + $taxAmount;

            $cashReceived = (float) ($validated['cash_received'] ?? $totalAmount);
            $changeReturned = max(0, $cashReceived - $totalAmount);

            $customerName = !empty($validated['customer_name']) ? $validated['customer_name'] : 'Walk-in Customer';
            $customerPhone = !empty($validated['customer_phone']) ? $validated['customer_phone'] : 'N/A';
            $customerEmail = !empty($validated['customer_email']) ? $validated['customer_email'] : ($customerPhone !== 'N/A' ? "pos-{$customerPhone}@pos.local" : 'walkin@pos.local');

            $orderNumber = 'POS-' . date('Ymd') . '-' . strtoupper(Str::random(5));
            $deliveryAddress = $validated['delivery_address'] ?? 'In-Store POS Terminal Pickup';
            $deliveryZone = $validated['delivery_zone'] ?? 'Dhaka';

            $combinedNotes = trim(
                (!empty($validated['customer_note']) ? "Customer Note: " . $validated['customer_note'] . "\n" : "") .
                (!empty($validated['admin_note']) ? "Admin Note: " . $validated['admin_note'] . "\n" : "") .
                ($validated['notes'] ?? "")
            );

            // Create Unified Order
            $order = Order::create([
                'user_id' => $validated['customer_id'] ?? null,
                'order_number' => $orderNumber,
                'order_source' => 'pos',
                'pos_register_session_id' => $session?->id,
                'cashier_user_id' => $request->user()->id,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'shipping_address' => [
                    'full_name' => $customerName,
                    'phone' => $customerPhone,
                    'address_line1' => $deliveryAddress,
                    'city' => $deliveryZone,
                    'postal_code' => '1200',
                    'country' => 'Bangladesh',
                ],
                'billing_address' => [
                    'full_name' => $customerName,
                    'phone' => $customerPhone,
                    'address_line1' => $deliveryAddress,
                    'city' => $deliveryZone,
                    'postal_code' => '1200',
                    'country' => 'Bangladesh',
                ],
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $orderDiscount,
                'total_amount' => $totalAmount,
                'cash_received' => $cashReceived,
                'change_returned' => $changeReturned,
                'payment_status' => $validated['payment_status'] ?? ($session ? 'paid' : 'pending'),
                'payment_method' => $validated['payment_method'] ?? 'cash_on_delivery',
                'payment_transaction_id' => 'pos_tx_' . Str::random(12),
                'order_status' => $validated['order_status'] ?? ($session ? 'delivered' : 'pending'),
                'delivered_at' => ($session ? now() : null),
                'notes' => $combinedNotes ?: 'POS System Order Checkout',
                'coupon_code' => $validated['coupon_code'] ?? null,
                'ip_address' => $request->ip(),
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            // 2. Consume FIFO Cost Layers & Compute Real COGS & Gross Profit
            try {
                $order = InventoryCostingService::fulfillOrderAndComputeCogs($order);
            } catch (\Throwable $e) {
                \Log::warning("POS Costing Warning: " . $e->getMessage());
            }

            // Post Real Double-Entry Sale Journal Entry to General Ledger
            try {
                \App\Services\AccountingService::postOrderSale($order);
            } catch (\Throwable $e) {
                \Log::warning("POS Accounting Warning: " . $e->getMessage());
            }

            // If converted from an abandoned checkout lead, automatically update lead status and order link
            if (!empty($validated['lead_id'])) {
                $lead = \App\Models\Lead::find($validated['lead_id']);
                if ($lead) {
                    $lead->update([
                        'status' => 'converted',
                        'converted_order_id' => $order->id,
                    ]);

                    AuditLog::log(
                        $request->user(),
                        'lead.converted_pos',
                        'Lead',
                        $lead->id,
                        "Lead #{$lead->id} successfully converted to Order #{$order->order_number} via POS Terminal.",
                        ['order_id' => $order->id, 'order_number' => $order->order_number]
                    );
                }
            }

            // 3. Update Register Session Totals if session is active
            if ($session) {
                $payMethod = $validated['payment_method'] ?? 'cash';
                if ($payMethod === 'cash') {
                    $session->increment('cash_sales_amount', $totalAmount);
                } elseif ($payMethod === 'credit_card' || $payMethod === 'debit_card') {
                    $session->increment('card_sales_amount', $totalAmount);
                } else {
                    $session->increment('mobile_sales_amount', $totalAmount);
                }

                $session->recalculateExpectedCash();
                $session->save();
            }

            AuditLog::log(
                $request->user(),
                'pos.sale_completed',
                'Order',
                $order->id,
                "User {$request->user()->name} processed POS Order {$order->order_number} (Total: \${$order->total_amount}, COGS: \${$order->cogs_amount}, Gross Profit: \${$order->gross_profit})."
            );

            return response()->json([
                'message' => "Sale completed successfully! Order #{$order->order_number}",
                'order' => $order->load(['items', 'cashier', 'posSession']),
                'receipt' => [
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'order_number' => $order->order_number,
                    'date' => $order->created_at->format('Y-m-d H:i:s'),
                    'cashier' => $request->user()->name,
                    'register' => $session?->register?->name ?? 'POS Terminal / Tele-sales',
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'items' => $order->items,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingAmount,
                    'discount' => $orderDiscount,
                    'tax' => $taxAmount,
                    'total' => $totalAmount,
                    'payment_method' => $validated['payment_method'] ?? 'cash_on_delivery',
                    'cash_received' => $cashReceived,
                    'change' => $changeReturned,
                ],
            ], 201);
        });
    }
}
