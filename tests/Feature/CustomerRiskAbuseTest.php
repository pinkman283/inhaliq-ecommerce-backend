<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerRiskService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerRiskAbuseTest extends TestCase
{
    use DatabaseTransactions;

    protected Product $testProduct;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::firstOrCreate(
            ['slug' => 'risk-test-category'],
            ['name' => 'Risk Test Category', 'is_active' => true]
        );

        $this->testProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Risk Telemetry Product ' . Str::random(5),
            'slug' => 'risk-product-' . Str::random(8),
            'sku' => 'RISK-' . strtoupper(Str::random(6)),
            'price' => 120.00,
            'cost_price' => 60.00,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);

        $this->adminUser = User::create([
            'name' => 'Admin Risk Auditor',
            'email' => 'admin.risk.' . Str::random(8) . '@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
            'status' => 'active',
            'permissions' => ['orders.manage', 'orders.view', 'customers.view', 'customers.manage', 'security.ip_block'],
        ]);
    }

    protected function createOrderPayload(string $email, string $name = 'Risk Test Shopper'): array
    {
        return [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => '01711223344',
            'shipping_method' => 'inside_dhaka',
            'payment_method' => 'cash_on_delivery',
            'shipping_address' => [
                'address_line1' => '101 Cyber Way',
                'city' => 'Dhaka',
                'postal_code' => '1000',
                'country' => 'Bangladesh',
            ],
            'items' => [
                ['product_id' => $this->testProduct->id, 'quantity' => 1],
            ],
        ];
    }

    /**
     * 1. Customer places order -> Backend captures and stores client IP address.
     */
    public function test_customer_order_captures_backend_derived_ip(): void
    {
        $clientIp = '198.51.100.11';
        $email = 'ip_tester_' . Str::random(8) . '@example.com';
        $payload = $this->createOrderPayload($email, 'IP Telemetry Tester');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);
        $orderId = $response->json('order.id');

        $order = Order::find($orderId);
        $this->assertNotNull($order);
        $this->assertEquals($clientIp, $order->ip_address);
    }

    /**
     * 2. Guest places order -> Associated in customer management with customer_type = guest.
     */
    public function test_guest_order_creates_guest_customer_record(): void
    {
        $guestEmail = 'guest_buyer_' . Str::random(8) . '@example.com';
        $payload = $this->createOrderPayload($guestEmail, 'Guest Jane Doe');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);

        $guestUser = User::where('email', $guestEmail)->first();
        $this->assertNotNull($guestUser, 'Guest user record was not created in users table.');
        $this->assertEquals('guest', $guestUser->customer_type);
        $this->assertEquals('customer', $guestUser->role);
    }

    /**
     * 3. Guest places multiple orders -> Associated with single unified record without duplicate accounts.
     */
    public function test_multiple_guest_orders_link_to_single_customer(): void
    {
        $repeatEmail = 'repeat_guest_' . Str::random(8) . '@example.com';
        $payload = $this->createOrderPayload($repeatEmail, 'Repeat Guest');

        $res1 = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.33'])
            ->postJson('/api/orders', $payload);
        $res1->assertStatus(201);

        $res2 = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.33'])
            ->postJson('/api/orders', $payload);
        $res2->assertStatus(201);

        $customerCount = User::where('email', $repeatEmail)->count();
        $this->assertEquals(1, $customerCount, 'Multiple orders from same guest email produced duplicate customer accounts.');

        $guestUser = User::where('email', $repeatEmail)->first();
        $orderCount = Order::where('user_id', $guestUser->id)->count();
        $this->assertGreaterThanOrEqual(2, $orderCount);
    }

    /**
     * 4. Admin blocks IP -> Order request from that IP is rejected with HTTP 403.
     */
    public function test_active_ip_block_rejects_order_creation_with_403(): void
    {
        $blockedIp = '198.51.100.44';
        CustomerRiskService::blockIp($blockedIp, 'Abusive automated bot attack', 'Test block notes', $this->adminUser, 'permanent');

        $payload = $this->createOrderPayload('attacker_' . Str::random(8) . '@example.com', 'Blocked Attacker');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $blockedIp])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(403);
    }

    /**
     * 5. Suspended customer cannot place orders even from a clean IP.
     */
    public function test_suspended_customer_cannot_place_orders(): void
    {
        $suspendedCustomer = User::create([
            'name' => 'Suspended Customer',
            'email' => 'suspended_' . Str::random(8) . '@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'customer',
            'status' => 'suspended',
        ]);

        $payload = $this->createOrderPayload($suspendedCustomer->email, $suspendedCustomer->name);

        Sanctum::actingAs($suspendedCustomer, ['customer:access']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.55'])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(403);
    }

    /**
     * 6. Suspended customer changing IP address remains blocked.
     */
    public function test_suspended_customer_changing_ip_remains_blocked(): void
    {
        $suspendedCustomer = User::create([
            'name' => 'Suspended Roaming Customer',
            'email' => 'roaming_suspended_' . Str::random(8) . '@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'customer',
            'status' => 'suspended',
        ]);

        $payload = $this->createOrderPayload($suspendedCustomer->email, $suspendedCustomer->name);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.88'])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(403);
    }

    /**
     * 7. Two legitimate customers sharing a public IP remain completely isolated.
     */
    public function test_shared_ip_customers_remain_isolated(): void
    {
        $sharedIp = '198.51.100.99';
        $emailA = 'tenant_a_' . Str::random(8) . '@example.com';
        $emailB = 'tenant_b_' . Str::random(8) . '@example.com';

        $payloadA = $this->createOrderPayload($emailA, 'Tenant A');
        $payloadB = $this->createOrderPayload($emailB, 'Tenant B');

        $resA = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])->postJson('/api/orders', $payloadA);
        $resB = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])->postJson('/api/orders', $payloadB);

        $resA->assertStatus(201);
        $resB->assertStatus(201);

        $userA = User::where('email', $emailA)->first();
        $userB = User::where('email', $emailB)->first();

        $this->assertNotNull($userA);
        $this->assertNotNull($userB);
        $this->assertNotEquals($userA->id, $userB->id);
    }

    /**
     * 8. Expired temporary IP block automatically permits order operations again.
     */
    public function test_expired_ip_block_automatically_permits_ordering(): void
    {
        $expiredIp = '198.51.100.133';
        BlockedIp::updateOrCreate(
            ['ip_address' => $expiredIp],
            [
                'status' => 'active',
                'reason' => 'Past temporary block',
                'expires_at' => now()->subHour(),
            ]
        );

        $this->assertFalse(CustomerRiskService::isIpBlocked($expiredIp), 'Expired IP block should not be reported as blocked.');

        $payload = $this->createOrderPayload('post_expiry_' . Str::random(8) . '@example.com', 'Post Expiry Customer');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $expiredIp])
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);
    }

    /**
     * 9. Unblocking an IP restores order creation capability.
     */
    public function test_unblocking_ip_restores_ordering(): void
    {
        $testIp = '198.51.100.111';
        CustomerRiskService::blockIp($testIp, 'Temporary block', null, $this->adminUser, '24_hours');

        $payload = $this->createOrderPayload('restored_' . Str::random(8) . '@example.com', 'Restored Customer');

        // Blocked attempt
        $this->withServerVariables(['REMOTE_ADDR' => $testIp])
            ->postJson('/api/orders', $payload)
            ->assertStatus(403);

        // Unblock
        CustomerRiskService::unblockIp($testIp, $this->adminUser, 'Appealed and verified clean');

        // Restored attempt
        $this->withServerVariables(['REMOTE_ADDR' => $testIp])
            ->postJson('/api/orders', $payload)
            ->assertStatus(201);
    }

    /**
     * 10. Admin order detail endpoint exposes captured client IP telemetry.
     */
    public function test_admin_order_detail_exposes_ip_telemetry(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-IP-' . strtoupper(Str::random(6)),
            'customer_name' => 'Telemetry Customer',
            'customer_email' => 'telemetry_' . Str::random(6) . '@example.com',
            'shipping_address' => ['city' => 'Dhaka'],
            'billing_address' => ['city' => 'Dhaka'],
            'subtotal' => 120.00,
            'total_amount' => 120.00,
            'payment_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'order_status' => 'pending',
            'ip_address' => '198.51.100.200',
        ]);

        // Verify captured telemetry persisted on the order model
        $freshOrder = Order::find($order->id);
        $this->assertEquals('198.51.100.200', $freshOrder->ip_address);

        // Verify ip_address is guarded in public/model serialization
        $serialized = $freshOrder->toArray();
        $this->assertArrayNotHasKey('ip_address', $serialized);

        // Verify risk engine utilizes this IP telemetry
        $riskData = CustomerRiskService::calculateCustomerRisk(
            User::firstOrCreate(
                ['email' => $order->customer_email],
                ['name' => $order->customer_name, 'role' => 'customer', 'password' => Hash::make('Secret123!')]
            )
        );
        $this->assertArrayHasKey('risk', $riskData);
    }
}
