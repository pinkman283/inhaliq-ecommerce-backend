<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerCartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicVariantCartCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-cart-checkout-cat'],
            ['name' => 'Cart Checkout Test Category', 'description' => 'Category for dynamic cart tests']
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'cart_checkout_tester@example.com'],
            [
                'name' => 'Cart Checkout Customer',
                'password' => bcrypt('password123'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );
    }

    private function createProduct(array $attributes = []): Product
    {
        $unique = Str::random(8);
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => 'Test Product ' . $unique,
            'slug' => 'test-prod-' . strtolower($unique),
            'sku' => 'SKU-' . strtoupper($unique),
            'price' => 100.00,
            'cost_price' => 50.00,
            'stock_quantity' => 50,
            'is_active' => true,
            'description' => 'Test product description.',
        ], $attributes));
    }

    private function createVariant(Product $product, array $attributes = []): ProductVariant
    {
        $unique = Str::random(6);
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Variant ' . $unique,
            'sku' => $product->sku . '-V-' . strtoupper($unique),
            'stock_quantity' => 20,
            'cost_price' => 40.00,
            'price_modifier' => 0.00,
            'price' => null,
            'is_active' => true,
            'attributes' => null,
        ], $attributes));
    }

    /**
     * 1. Adding an active dynamic variant to the cart.
     */
    public function test_1_adding_active_dynamic_variant_to_cart(): void
    {
        $product = $this->createProduct(['price' => 100.00]);
        $variant = $this->createVariant($product, [
            'name' => 'Midnight Blue / 256GB',
            'price' => 180.00,
            'is_active' => true,
            'stock_quantity' => 15,
            'attributes' => ['Color' => 'Midnight Blue', 'Storage' => '256GB'],
        ]);

        // Stateless cart validation
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'price' => 180.00,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('items.0.status', 'valid');
        $this->assertEquals(180.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(360.00, (float) $response->json('items.0.total_price'));

        // Persistent customer cart sync
        Sanctum::actingAs($this->customer, ['customer:access']);
        $syncResponse = $this->postJson('/api/customer/cart/sync', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $syncResponse->assertOk()
            ->assertJsonPath('items.0.variant_id', $variant->id)
            ->assertJsonPath('items.0.variant.attributes.Color', 'Midnight Blue')
            ->assertJsonPath('items.0.variant.attributes.Storage', '256GB')
            ->assertJsonPath('items.0.variant.is_active', true);
        $this->assertEquals(180.00, (float) $syncResponse->json('items.0.unit_price'));
        $this->assertEquals(360.00, (float) $syncResponse->json('items.0.total_price'));
        $this->assertEquals(180.00, (float) $syncResponse->json('items.0.variant.effective_price'));
    }

    /**
     * 2. Rejecting an inactive variant for a new cart purchase.
     */
    public function test_2_rejecting_inactive_variant_for_new_cart_purchase(): void
    {
        $product = $this->createProduct(['price' => 100.00]);
        $inactiveVariant = $this->createVariant($product, [
            'name' => 'Discontinued Option',
            'price' => 150.00,
            'is_active' => false,
            'stock_quantity' => 10,
            'attributes' => ['Color' => 'Red'],
        ]);

        // Stateless cart validation marks as invalid
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $inactiveVariant->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('summary.has_inactive_items', true)
            ->assertJsonPath('items.0.status', 'inactive_variant')
            ->assertJsonPath('items.0.is_valid', false);

        // Persistent customer cart sync discards inactive variant
        Sanctum::actingAs($this->customer, ['customer:access']);
        $syncResponse = $this->postJson('/api/customer/cart/sync', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $inactiveVariant->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $syncResponse->assertOk()
            ->assertJsonPath('summary.total_items', 0)
            ->assertJsonCount(0, 'items');
    }

    /**
     * 3. Rejecting a variant belonging to a different product.
     */
    public function test_3_rejecting_variant_belonging_to_different_product(): void
    {
        $productA = $this->createProduct(['name' => 'Product Alpha']);
        $productB = $this->createProduct(['name' => 'Product Beta']);
        $variantB = $this->createVariant($productB, ['name' => 'Beta Variant']);

        // Mismatched in cart validation
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variant_id' => $variantB->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('items.0.status', 'variant_mismatch')
            ->assertJsonPath('items.0.is_valid', false);

        // Mismatched in checkout
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'customer_phone' => '01811223344',
            'shipping_address' => [
                'address_line1' => 'Road 1',
                'city' => 'Dhaka',
            ],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variant_id' => $variantB->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $checkoutResponse->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * 4. Ignoring or rejecting client-supplied prices as authoritative.
     */
    public function test_4_ignoring_client_supplied_prices_as_authoritative(): void
    {
        $product = $this->createProduct(['price' => 100.00]);
        $variant = $this->createVariant($product, [
            'name' => 'Premium Dynamic Variant',
            'price' => 160.00,
            'stock_quantity' => 10,
        ]);

        // Client attempts to claim unit_price is 10.00
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'price' => 10.00,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('items.0.price_changed', true);
        $this->assertEquals(160.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(320.00, (float) $response->json('items.0.total_price'));
        $this->assertEquals(320.00, (float) $response->json('summary.subtotal'));

        // Checkout completely ignores any client-supplied price
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Buyer',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '01711223344',
            'shipping_address' => [
                'address_line1' => 'Dhanmondi',
                'city' => 'Dhaka',
            ],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'price' => 5.00, // Attempted tamper
                ],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $order = $checkoutResponse->json('order');
        $this->assertEquals(320.00, (float) $order['subtotal']);
        $this->assertEquals(160.00, (float) $order['items'][0]['unit_price']);
    }

    /**
     * 5. Direct variant price taking precedence.
     */
    public function test_5_direct_variant_price_taking_precedence(): void
    {
        $product = $this->createProduct(['price' => 100.00]);
        // Variant has direct price 220.00, but price_modifier 30.00
        // Precedence rule: direct price 220.00 must be used, NOT 100 + 30 = 130
        $variant = $this->createVariant($product, [
            'name' => 'Precedence Test Variant',
            'price' => 220.00,
            'price_modifier' => 30.00,
            'stock_quantity' => 10,
        ]);

        $this->assertEquals(220.00, $variant->effective_price);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertEquals(220.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(220.00, (float) $response->json('items.0.total_price'));

        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Precedence Tester',
            'customer_email' => 'precedence@example.com',
            'shipping_address' => ['address_line1' => 'Road 2', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $this->assertEquals(220.00, (float) $checkoutResponse->json('order.items.0.unit_price'));
    }

    /**
     * 6. Legacy price-modifier fallback.
     */
    public function test_6_legacy_price_modifier_fallback(): void
    {
        $product = $this->createProduct(['price' => 80.00]);
        // Legacy variant: price is null, price_modifier is +25.00
        $variant = $this->createVariant($product, [
            'name' => 'Legacy Size XL',
            'price' => null,
            'price_modifier' => 25.00,
            'stock_quantity' => 10,
        ]);

        $this->assertEquals(105.00, $variant->effective_price);

        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertEquals(105.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(210.00, (float) $response->json('items.0.total_price'));

        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Legacy Tester',
            'customer_email' => 'legacy@example.com',
            'shipping_address' => ['address_line1' => 'Road 3', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $this->assertEquals(105.00, (float) $checkoutResponse->json('order.items.0.unit_price'));
        $this->assertEquals(210.00, (float) $checkoutResponse->json('order.subtotal'));
    }

    /**
     * 7. Correct variant stock validation.
     */
    public function test_7_correct_variant_stock_validation(): void
    {
        $product = $this->createProduct(['stock_quantity' => 100]);
        $variant = $this->createVariant($product, [
            'name' => 'Limited Stock Variant',
            'stock_quantity' => 3,
        ]);

        // Requesting 5 should adjust quantity to 3 in cart validation
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 5,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('items.0.status', 'quantity_adjusted')
            ->assertJsonPath('items.0.normalized_quantity', 3)
            ->assertJsonPath('items.0.available_stock', 3);

        // Attempting checkout for 4 units should be rejected with 422
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Stock Tester',
            'customer_email' => 'stock@example.com',
            'shipping_address' => ['address_line1' => 'Road 4', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 4],
            ],
        ]);

        $checkoutResponse->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * 8. Checkout rejection when a variant is inactive or unavailable.
     */
    public function test_8_checkout_rejection_when_variant_is_inactive_or_unavailable(): void
    {
        $product = $this->createProduct(['stock_quantity' => 50]);
        $variant = $this->createVariant($product, [
            'name' => 'Inactive Checkout Variant',
            'is_active' => false,
            'stock_quantity' => 10,
        ]);

        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Inactive Checkout Tester',
            'customer_email' => 'inactive_checkout@example.com',
            'shipping_address' => ['address_line1' => 'Road 5', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1],
            ],
        ]);

        $checkoutResponse->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $errorMsg = $checkoutResponse->json('errors.items.0');
        $this->assertStringContainsString('inactive', strtolower($errorMsg));
    }

    /**
     * 9. Correct variant_attributes snapshot on new order items.
     */
    public function test_9_correct_variant_attributes_snapshot_on_new_order_items(): void
    {
        $product = $this->createProduct(['price' => 150.00]);
        $variant = $this->createVariant($product, [
            'name' => 'Space Grey / 512GB',
            'price' => 299.99,
            'stock_quantity' => 10,
            'attributes' => [
                'Color' => 'Space Grey',
                'Storage' => '512GB',
            ],
        ]);

        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Snapshot Customer',
            'customer_email' => 'snapshot@example.com',
            'shipping_address' => ['address_line1' => 'Road 6', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $orderId = $checkoutResponse->json('order.id');

        $orderItem = OrderItem::where('order_id', $orderId)->firstOrFail();

        $this->assertEquals($variant->id, $orderItem->variant_id);
        $this->assertEquals('Space Grey / 512GB', $orderItem->variant_name);
        $this->assertEquals(299.99, (float) $orderItem->unit_price);
        $this->assertIsArray($orderItem->variant_attributes);
        $this->assertEquals('Space Grey', $orderItem->variant_attributes['Color']);
        $this->assertEquals('512GB', $orderItem->variant_attributes['Storage']);
    }

    /**
     * 10. Existing orders with null variant_attributes remain compatible.
     */
    public function test_10_existing_orders_with_null_variant_attributes_remain_compatible(): void
    {
        $product = $this->createProduct();
        $orderNumber = 'ORD-HISTORIC-' . strtoupper(Str::random(6));

        $order = Order::create([
            'order_number' => $orderNumber,
            'customer_name' => 'Historic Buyer',
            'customer_email' => 'historic@example.com',
            'shipping_address' => ['address_line1' => 'Old Street', 'city' => 'Dhaka'],
            'subtotal' => 100.00,
            'total_amount' => 160.00,
            'shipping_amount' => 60.00,
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => 100.00,
            'quantity' => 1,
            'total_price' => 100.00,
            'variant_attributes' => null, // Historic order with null attributes
        ]);

        $response = $this->getJson("/api/orders/{$orderNumber}");

        $response->assertOk()
            ->assertJsonPath('order_number', $orderNumber)
            ->assertJsonPath('items.0.product_name', $product->name)
            ->assertJsonPath('items.0.variant_attributes', null);
    }

    /**
     * 11. Simple-product cart and checkout regression coverage.
     */
    public function test_11_simple_product_cart_and_checkout_regression(): void
    {
        $product = $this->createProduct([
            'price' => 75.00,
            'stock_quantity' => 12,
        ]);

        // Cart validation for simple product
        $response = $this->postJson('/api/cart/validate', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => 2,
                    'price' => 75.00,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('is_valid', true);
        $this->assertEquals(75.00, (float) $response->json('items.0.unit_price'));
        $this->assertEquals(150.00, (float) $response->json('items.0.total_price'));

        // Checkout simple product
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Simple Buyer',
            'customer_email' => 'simple@example.com',
            'shipping_address' => ['address_line1' => 'Road 7', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $order = $checkoutResponse->json('order');
        $this->assertEquals(150.00, (float) $order['subtotal']);
        $this->assertNull($order['items'][0]['variant_id']);
        $this->assertNull($order['items'][0]['variant_attributes']);

        // Stock decrement
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    /**
     * 12. Legacy color/size variant regression coverage.
     */
    public function test_12_legacy_color_size_variant_regression(): void
    {
        $product = $this->createProduct(['price' => 60.00, 'stock_quantity' => 30]);
        $legacyVariant = $this->createVariant($product, [
            'name' => 'Red / L',
            'color_name' => 'Red',
            'size' => 'L',
            'price_modifier' => 15.00,
            'price' => null,
            'stock_quantity' => 15,
            'attributes' => null, // Pure legacy variant
        ]);

        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Legacy Color Buyer',
            'customer_email' => 'legacy_color@example.com',
            'shipping_address' => ['address_line1' => 'Road 8', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $legacyVariant->id, 'quantity' => 1],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $orderItem = $checkoutResponse->json('order.items.0');
        $this->assertEquals(75.00, (float) $orderItem['unit_price']);
        $this->assertEquals('Red / L', $orderItem['variant_name']);
        $this->assertNull($orderItem['variant_attributes']);

        // Decrements
        $this->assertEquals(14, $legacyVariant->fresh()->stock_quantity);
        $this->assertEquals(29, $product->fresh()->stock_quantity);
    }

    /**
     * 13. Coupon, discount, and total calculations remain correct.
     */
    public function test_13_coupon_discount_and_total_calculations_remain_correct(): void
    {
        $product = $this->createProduct(['price' => 100.00]);
        $variant = $this->createVariant($product, [
            'name' => 'Promo Variant',
            'price' => 200.00,
            'stock_quantity' => 10,
        ]);

        $promo = Promotion::create([
            'name' => '10% Dynamic Discount',
            'slug' => 'promo-dyn-' . Str::random(6),
            'promotion_type' => 'discount_code',
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'status' => 'active',
            'is_active' => true,
            'show_on_storefront' => false,
            'applies_to' => 'entire_order',
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDays(10),
        ]);

        $code = 'DYNAMIC10_' . strtoupper(Str::random(4));
        PromotionCode::create([
            'promotion_id' => $promo->id,
            'code' => $code,
            'is_active' => true,
        ]);

        // Cart validation with coupon
        $response = $this->postJson('/api/cart/validate', [
            'coupon_code' => $code,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'price' => 200.00,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('coupon.valid', true);
        $this->assertEquals(20.00, (float) $response->json('coupon.discount_amount'));
        $this->assertEquals(200.00, (float) $response->json('summary.subtotal'));

        // Checkout with coupon
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Coupon Buyer',
            'customer_email' => 'coupon_buyer@example.com',
            'shipping_address' => ['address_line1' => 'Road 9', 'city' => 'Dhaka'],
            'payment_method' => 'cash_on_delivery',
            'shipping_method' => 'inside_dhaka',
            'coupon_code' => $code,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1],
            ],
        ]);

        $checkoutResponse->assertStatus(201);
        $order = $checkoutResponse->json('order');
        $this->assertEquals(200.00, (float) $order['subtotal']);
        $this->assertEquals(20.00, (float) $order['discount_amount']);
        $this->assertEquals(60.00, (float) $order['shipping_amount']);
        $this->assertEquals(240.00, (float) $order['total_amount']); // 200 - 20 + 60
    }

    /**
     * 14. Transaction rollback and stock integrity on checkout failure.
     */
    public function test_14_transaction_rollback_and_stock_integrity_on_checkout_failure(): void
    {
        $product = $this->createProduct(['price' => 100.00, 'stock_quantity' => 20]);
        $variant = $this->createVariant($product, [
            'name' => 'Rollback Variant',
            'price' => 150.00,
            'stock_quantity' => 10,
        ]);

        // Attempt checkout with an invalid payment method to trigger an exception during transaction
        $checkoutResponse = $this->postJson('/api/orders', [
            'customer_name' => 'Rollback Buyer',
            'customer_email' => 'rollback@example.com',
            'shipping_address' => ['address_line1' => 'Road 10', 'city' => 'Dhaka'],
            'payment_method' => 'invalid_unsupported_gateway',
            'shipping_method' => 'inside_dhaka',
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            ],
        ]);

        $checkoutResponse->assertStatus(422);

        // Verify stock was not decremented due to rollback
        $this->assertEquals(20, $product->fresh()->stock_quantity);
        $this->assertEquals(10, $variant->fresh()->stock_quantity);
    }
}
