<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Shipment;
use Tests\TestCase;

class CourierWebhookSecurityTest extends TestCase
{
    public function test_rejects_webhook_with_missing_or_invalid_signature(): void
    {
        $response = $this->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => '123456',
            'status' => 'delivered',
        ]);

        $response->assertStatus(401);
    }

    public function test_delivered_terminal_shipment_is_not_reverted_by_out_of_order_webhook(): void
    {
        $order = Order::factory()->create([
            'order_status' => 'delivered',
            'payment_status' => 'paid',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'provider' => 'steadfast',
            'consignment_id' => 'CID-99999',
            'tracking_code' => 'TRK-99999',
            'recipient_name' => 'Test Recipient',
            'recipient_phone' => '01711000111',
            'recipient_address' => 'Dhaka, Bangladesh',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        // Provide test token matching config
        config(['services.steadfast.secret_key' => 'valid-test-secret-key-1234']);

        // Out of order webhook arrives with 'in_transit'
        $response = $this->withHeaders([
            'X-Steadfast-Token' => 'valid-test-secret-key-1234',
        ])->postJson('/api/webhooks/courier/steadfast', [
            'consignment_id' => 'CID-99999',
            'status' => 'in_transit',
        ]);

        $response->assertStatus(200);

        // Shipment status must remain 'delivered', NOT reverted to 'in_transit'
        $shipment->refresh();
        $this->assertEquals('delivered', $shipment->status);
    }
}
