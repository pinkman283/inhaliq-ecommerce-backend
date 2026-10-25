<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreCreditAccount;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreCreditCheckoutAuthTest extends TestCase
{
    public function test_unauthenticated_guest_cannot_use_store_credit_even_with_victim_email(): void
    {
        // 1. Setup victim user with ৳1000 in store credit
        $victim = User::factory()->create([
            'role' => 'customer',
            'email' => 'victim_' . uniqid() . '@example.com',
            'status' => 'active',
        ]);

        $account = StoreCreditAccount::create([
            'user_id' => $victim->id,
            'balance' => 1000.00,
            'total_credited' => 1000.00,
            'total_debited' => 0.00,
            'is_frozen' => false,
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 500.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // 2. Unauthenticated attacker attempts guest checkout with victim email and store credit checked
        $payload = [
            'customer_name' => 'Attacker Person',
            'customer_email' => $victim->email,
            'customer_phone' => '01711999888',
            'shipping_address' => [
                'address_line1' => 'Attacker Hideout 12',
                'city' => 'Dhaka',
                'postal_code' => '1200',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'standard_inside_dhaka',
            'payment_method' => 'cod',
            'use_store_credit' => true,
            'store_credit_amount' => 500.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);

        // 3. Must be rejected with HTTP 422 validation error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['use_store_credit']);

        // 4. Victim balance must remain completely untouched
        $account->refresh();
        $this->assertEquals(1000.00, (float) $account->balance);
    }

    public function test_authenticated_customer_can_use_own_store_credit(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'email' => 'legit_' . uniqid() . '@example.com',
            'status' => 'active',
        ]);

        $account = StoreCreditAccount::create([
            'user_id' => $customer->id,
            'balance' => 1000.00,
            'total_credited' => 1000.00,
            'total_debited' => 0.00,
            'is_frozen' => false,
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 300.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        Sanctum::actingAs($customer, ['customer:access']);

        $payload = [
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '01711222333',
            'shipping_address' => [
                'address_line1' => 'Customer Home',
                'city' => 'Dhaka',
                'postal_code' => '1200',
                'country' => 'Bangladesh',
            ],
            'shipping_method' => 'standard_inside_dhaka',
            'payment_method' => 'cod',
            'use_store_credit' => true,
            'store_credit_amount' => 300.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);
        $response->assertStatus(201);

        $account->refresh();
        $this->assertLessThan(1000.00, (float) $account->balance);
    }
}
