<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    protected User $customerAlice;
    protected User $customerBob;
    protected User $adminUser;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'security-hardening-cat'],
            ['name' => 'Security Hardening Category', 'is_active' => true]
        );

        $this->customerAlice = User::create([
            'name' => 'Alice Security',
            'email' => 'alice.sec.' . uniqid() . '@example.com',
            'password' => bcrypt('StrongPass123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
            'risk_level' => 'high',
            'risk_score' => 85,
            'internal_notes' => 'CONFIDENTIAL: Suspected duplicate address abuse',
            'failed_login_attempts' => 2,
        ]);

        $this->customerBob = User::create([
            'name' => 'Bob Security',
            'email' => 'bob.sec.' . uniqid() . '@example.com',
            'password' => bcrypt('StrongPass123!'),
            'role' => 'customer',
            'customer_type' => 'registered',
            'status' => 'active',
            'risk_level' => 'low',
            'risk_score' => 10,
        ]);

        $this->adminUser = User::create([
            'name' => 'Admin Security',
            'email' => 'admin.sec.' . uniqid() . '@example.com',
            'password' => bcrypt('AdminPass123!'),
            'role' => 'admin',
            'status' => 'active',
            'permissions' => ['products.manage', 'orders.manage', 'customers.view'],
        ]);
    }

    protected function createOrderFor(User $owner, array $attributes = []): Order
    {
        $orderNumber = 'ORD-SEC-' . strtoupper(Str::random(8));
        $order = Order::create(array_merge([
            'order_number' => $orderNumber,
            'user_id' => $owner->id,
            'customer_email' => $owner->email,
            'customer_name' => $owner->name,
            'subtotal' => 150.00,
            'total_amount' => 150.00,
            'cogs_amount' => 60.00,
            'gross_profit' => 90.00,
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'cod',
            'shipping_address' => ['address' => '123 Privacy Ave, Suite 100', 'city' => 'SecCity'],
            'ip_address' => '192.168.1.100',
        ], $attributes));

        return $order;
    }

    public function test_customer_cannot_access_another_customers_order_by_order_number(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice);

        // Bob tries to view Alice's order
        Sanctum::actingAs($this->customerBob, ['customer:access']);

        $response = $this->getJson("/api/orders/{$alicesOrder->order_number}");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Access Denied: You do not have authorization to view this customer order.',
            ]);
    }

    public function test_unauthenticated_guest_cannot_access_customer_order_by_order_number(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice);

        // Guest without token tries to view Alice's order
        $response = $this->getJson("/api/orders/{$alicesOrder->order_number}");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Access Denied: You do not have authorization to view this customer order.',
            ]);
    }

    public function test_authenticated_customer_can_access_their_own_order_confirmation(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice);

        // Alice views her own order
        Sanctum::actingAs($this->customerAlice, ['customer:access']);

        $response = $this->getJson("/api/orders/{$alicesOrder->order_number}");

        $response->assertStatus(200)
            ->assertJson([
                'order_number' => $alicesOrder->order_number,
                'customer_email' => $this->customerAlice->email,
            ]);
    }

    public function test_order_cannot_be_accessed_by_sequential_integer_id(): void
    {
        $order = $this->createOrderFor($this->customerAlice);

        // Attempting to query by integer ID should result in 404 (not found by order_number)
        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertStatus(404);
    }

    public function test_customer_cannot_cancel_another_customers_order(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice, ['order_status' => 'pending']);

        Sanctum::actingAs($this->customerBob, ['customer:access']);

        $response = $this->postJson("/api/orders/{$alicesOrder->order_number}/cancel");

        $response->assertStatus(403);
        $this->assertEquals('pending', $alicesOrder->fresh()->order_status);
    }

    public function test_customer_cannot_return_another_customers_order(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice, ['order_status' => 'delivered']);

        Sanctum::actingAs($this->customerBob, ['customer:access']);

        $response = $this->postJson("/api/orders/{$alicesOrder->order_number}/return", [
            'return_reason' => 'Defective item',
        ]);

        $response->assertStatus(403);
    }

    public function test_customer_profile_never_exposes_internal_risk_or_security_metadata(): void
    {
        Sanctum::actingAs($this->customerAlice, ['customer:access']);

        $response = $this->getJson('/api/auth/profile');

        $response->assertStatus(200);

        $json = $response->json();
        $userData = $json['user'];

        // Sensitive fraud risk and operational metadata must NEVER be present in customer response
        $this->assertArrayNotHasKey('risk_level', $userData);
        $this->assertArrayNotHasKey('risk_score', $userData);
        $this->assertArrayNotHasKey('risk_reasons', $userData);
        $this->assertArrayNotHasKey('internal_notes', $userData);
        $this->assertArrayNotHasKey('failed_login_attempts', $userData);
        $this->assertArrayNotHasKey('locked_until', $userData);
        $this->assertArrayNotHasKey('password', $userData);
    }

    public function test_order_response_never_exposes_internal_cogs_or_pos_cashier_metadata(): void
    {
        $alicesOrder = $this->createOrderFor($this->customerAlice);

        Sanctum::actingAs($this->customerAlice, ['customer:access']);

        $response = $this->getJson("/api/orders/{$alicesOrder->order_number}");

        $response->assertStatus(200);

        $orderData = $response->json();

        // Sensitive COGS, margin, and internal POS cashier metadata must be hidden
        $this->assertArrayNotHasKey('cogs_amount', $orderData);
        $this->assertArrayNotHasKey('gross_profit', $orderData);
        $this->assertArrayNotHasKey('pos_register_session_id', $orderData);
        $this->assertArrayNotHasKey('cashier_user_id', $orderData);
        $this->assertArrayNotHasKey('ip_address', $orderData);
    }

    public function test_customer_token_cannot_access_admin_api_endpoints(): void
    {
        // Customer Alice with customer:access token
        Sanctum::actingAs($this->customerAlice, ['customer:access']);

        $response = $this->getJson('/api/admin/products');

        // Blocked by ability:admin:access and EnsureAdmin
        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_access_protected_customer_endpoints(): void
    {
        $response = $this->getJson('/api/auth/profile');
        $response->assertStatus(401);

        $response = $this->getJson('/api/customer/cart');
        $response->assertStatus(401);

        $response = $this->getJson('/api/customer/wishlist');
        $response->assertStatus(401);
    }

    public function test_security_headers_are_attached_to_api_responses(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    public function test_health_endpoint_returns_sanitized_status_without_leaking_credentials_or_pdo_errors(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'environment',
            'checks' => [
                'database' => ['status', 'message'],
                'cache' => ['status', 'message'],
                'storage' => ['status', 'message'],
            ],
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($body));
        $this->assertStringNotContainsString('root', strtolower($body));
        $this->assertStringNotContainsString('sqlstate', strtolower($body));
    }
}
