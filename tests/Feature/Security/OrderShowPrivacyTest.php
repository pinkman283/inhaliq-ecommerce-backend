<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderShowPrivacyTest extends TestCase
{
    public function test_unauthenticated_stranger_cannot_view_unlinked_guest_order_pii(): void
    {
        $order = Order::factory()->create([
            'user_id' => null,
            'order_number' => 'ORD-GUEST-' . strtoupper(uniqid()),
            'customer_name' => 'Secret Customer',
            'customer_email' => 'secret@example.com',
            'customer_phone' => '01711000999',
            'shipping_address' => [
                'address_line1' => 'Confidential House 42, Road 7',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'total_amount' => 1200.00,
            'order_status' => 'pending',
        ]);

        // Stranger attempts direct show without credentials
        $response = $this->getJson("/api/orders/{$order->order_number}");

        // Must NOT return full raw unmasked phone, address, and email to unauthenticated stranger
        $response->assertStatus(403);
    }

    public function test_guest_with_matching_verification_credential_can_view_order(): void
    {
        $order = Order::factory()->create([
            'user_id' => null,
            'order_number' => 'ORD-GUEST-' . strtoupper(uniqid()),
            'customer_name' => 'Valid Guest',
            'customer_email' => 'valid.guest@example.com',
            'customer_phone' => '01711888777',
            'shipping_address' => [
                'address_line1' => 'House 10, Road 1',
                'city' => 'Dhaka',
                'postal_code' => '1212',
                'country' => 'Bangladesh',
            ],
            'total_amount' => 800.00,
            'order_status' => 'pending',
        ]);

        // Legitimate guest provides their phone or email verification
        $response = $this->getJson("/api/orders/{$order->order_number}?email=valid.guest@example.com");
        $response->assertStatus(200);
        $response->assertJsonPath('order_number', $order->order_number);
    }
}
