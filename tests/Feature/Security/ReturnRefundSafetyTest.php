<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnRefundSafetyTest extends TestCase
{
    public function test_cannot_refund_an_already_refunded_return(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin, ['admin:access']);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'total_amount' => 1000.00,
            'amount_refunded' => 500.00,
            'payment_status' => 'paid',
        ]);

        $orderReturn = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'return_number' => 'RET-' . uniqid(),
            'return_type' => 'customer_return',
            'status' => 'received',
            'inspection_status' => 'passed',
            'inspected_at' => now(),
            'refund_status' => 'processed', // Already refunded!
            'refund_amount' => 500.00,
            'refund_method' => 'cash',
        ]);

        $response = $this->postJson("/api/admin/returns/{$orderReturn->id}/refund", [
            'refund_amount' => 500.00,
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_refund_amount_exceeding_order_remaining_refundable_balance(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin, ['admin:access']);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'total_amount' => 500.00,
            'amount_refunded' => 400.00, // Only 100 remaining!
            'payment_status' => 'paid',
        ]);

        $orderReturn = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'return_number' => 'RET-' . uniqid(),
            'return_type' => 'customer_return',
            'status' => 'received',
            'inspection_status' => 'passed',
            'inspected_at' => now(),
            'refund_status' => 'pending',
            'refund_amount' => 0.00,
            'refund_method' => 'none',
        ]);

        // Attempt to refund 300 when only 100 is refundable
        $response = $this->postJson("/api/admin/returns/{$orderReturn->id}/refund", [
            'refund_amount' => 300.00,
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(422);
    }
}
