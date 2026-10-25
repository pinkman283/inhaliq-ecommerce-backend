<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Order;
use Tests\TestCase;

class OrderCheckoutIntegrationTest extends TestCase
{
    public function test_checkout_successfully_creates_order_and_decrements_stock(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 1200.00,
            'cost_price' => 700.00,
            'stock_quantity' => 10,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price_modifier' => 100.00,
            'stock_quantity' => 5,
        ]);

        $payload = [
            'customer_name' => 'John Doe',
            'customer_email' => 'john.doe@example.com',
            'customer_phone' => '01700000001',
            'shipping_address' => [
                'address_line1' => 'Gulshan 2',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'standard_inside_dhaka',
            'payment_method' => 'cod',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);

        $response->assertStatus(201);
        $orderData = $response->json('order') ?? $response->json('data') ?? $response->json();
        
        $this->assertNotEmpty($orderData);
        $orderId = $orderData['id'] ?? null;
        $this->assertNotNull($orderId);

        // Verify Database Persistence
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_name' => 'John Doe',
            'payment_method' => 'cash_on_delivery',
        ]);

        // Verify stock decremented
        $variant->refresh();
        $this->assertEquals(3, $variant->stock_quantity); // 5 - 2 = 3
    }

    public function test_checkout_fails_gracefully_when_quantity_exceeds_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 2]);

        $payload = [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '01700000002',
            'shipping_address' => [
                'address_line1' => 'Banani',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'standard_inside_dhaka',
            'payment_method' => 'cod',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 10, // Exceeds stock of 2
                ],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);

        // Should return 422 Unprocessable or 400 Bad Request
        $this->assertContains($response->status(), [400, 422]);
    }
}
