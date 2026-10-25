<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreCreditAccount;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCancellationCreditRefundTest extends TestCase
{
    public function test_cancelling_order_restores_store_credit_to_customer_wallet(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
        ]);
        Sanctum::actingAs($customer, ['customer:access']);

        $account = StoreCreditAccount::create([
            'user_id' => $customer->id,
            'balance' => 200.00, // 500 originally, 300 used on order
            'total_credited' => 500.00,
            'total_debited' => 300.00,
            'is_frozen' => false,
        ]);

        $product = Product::factory()->create([
            'price' => 500.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'total_amount' => 200.00,
            'store_credit_amount' => 300.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => 500.00,
            'quantity' => 1,
            'total_price' => 500.00,
        ]);

        // Customer cancels the order
        $response = $this->postJson("/api/orders/{$order->order_number}/cancel");
        $response->assertStatus(200);

        // Wallet balance must be restored by 300 (200 + 300 = 500)
        $account->refresh();
        $this->assertEquals(500.00, (float) $account->balance);

        // Transaction must be logged
        $this->assertDatabaseHas('store_credit_transactions', [
            'account_id' => $account->id,
            'user_id' => $customer->id,
            'type' => 'refund',
            'amount' => 300.00,
            'reference_type' => 'Order',
            'reference_id' => (string) $order->id,
        ]);
    }
}
