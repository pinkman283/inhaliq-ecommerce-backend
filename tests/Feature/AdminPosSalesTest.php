<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\PosRegisterSession;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPosSalesTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Category $testCategory;
    protected Product $testProduct;
    protected PosRegister $register;
    protected PosRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name' => 'Admin POS Tester',
            'email' => 'pos.admin.' . Str::random(8) . '@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->testCategory = Category::firstOrCreate(
            ['slug' => 'pos-test-category'],
            ['name' => 'POS Test Category', 'is_active' => true]
        );

        $this->testProduct = Product::create([
            'category_id' => $this->testCategory->id,
            'name' => 'POS Retail Item ' . Str::random(5),
            'slug' => 'pos-item-' . Str::random(8),
            'sku' => 'POS-' . strtoupper(Str::random(6)),
            'price' => 50.00,
            'cost_price' => 25.00,
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        $this->register = PosRegister::firstOrCreate(
            ['code' => 'REG-MAIN'],
            ['name' => 'Main POS Register', 'status' => 'open']
        );

        $this->session = PosRegisterSession::create([
            'pos_register_id' => $this->register->id,
            'user_id' => $this->adminUser->id,
            'opened_at' => now(),
            'opening_balance' => 150.00,
            'expected_cash_balance' => 150.00,
            'cash_sales_amount' => 0.00,
            'card_sales_amount' => 0.00,
            'mobile_sales_amount' => 0.00,
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->adminUser, ['admin:access']);
    }

    /**
     * 1. Admin can search and list POS catalog products.
     */
    public function test_admin_can_list_pos_products(): void
    {
        $response = $this->getJson('/api/admin/pos/products?search=' . urlencode($this->testProduct->name));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'per_page',
                'total',
            ]);

        $this->assertNotEmpty($response->json('data'));
        $found = collect($response->json('data'))->firstWhere('id', $this->testProduct->id);
        $this->assertNotNull($found, 'Created test product was not found in POS product query.');
    }

    /**
     * 2. POS checkout successfully creates an in-store order, decrements stock, and updates cash drawer.
     */
    public function test_pos_checkout_creates_order_and_updates_inventory_and_register_cash(): void
    {
        $initialStock = $this->testProduct->stock_quantity;
        $initialCashSales = (float) $this->session->cash_sales_amount;

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'customer_name' => 'Walk-in Shopper',
            'customer_phone' => '01700000000',
            'payment_method' => 'cash',
            'cash_received' => 150.00,
            'discount_amount' => 10.00,
            'tax_amount' => 0.00,
            'notes' => 'Counter test order',
            'items' => [
                [
                    'product_id' => $this->testProduct->id,
                    'unit_price' => 50.00,
                    'quantity' => 2,
                    'discount_amount' => 0.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'order' => [
                    'id',
                    'order_number',
                    'order_source',
                    'total_amount',
                    'payment_status',
                ],
                'receipt' => [
                    'order_id',
                    'order_number',
                    'cashier',
                    'total',
                    'payment_method',
                ],
            ]);

        // Total calculation: (50 * 2) - 10 discount = 90.00
        $this->assertEquals(90.00, (float) $response->json('order.total_amount'));
        $this->assertEquals('pos', $response->json('order.order_source'));
        $this->assertEquals('paid', $response->json('order.payment_status'));

        // Verify stock decremented by 2
        $this->testProduct->refresh();
        $this->assertEquals($initialStock - 2, $this->testProduct->stock_quantity);

        // Verify register session cash total increased
        $this->session->refresh();
        $this->assertEquals($initialCashSales + 90.00, (float) $this->session->cash_sales_amount);
    }

    /**
     * 3. POS checkout rejects sale when the register session is closed.
     */
    public function test_pos_checkout_rejects_closed_register_session(): void
    {
        $closedSession = PosRegisterSession::create([
            'pos_register_id' => $this->register->id,
            'user_id' => $this->adminUser->id,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHour(),
            'opening_balance' => 100.00,
            'status' => 'closed',
        ]);

        $payload = [
            'pos_register_session_id' => $closedSession->id,
            'payment_method' => 'cash',
            'cash_received' => 50.00,
            'items' => [
                [
                    'product_id' => $this->testProduct->id,
                    'unit_price' => 50.00,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected register session is closed.',
            ]);
    }

    /**
     * 4. POS checkout enforces validation on mandatory payload properties.
     */
    public function test_pos_checkout_validates_required_fields(): void
    {
        $response = $this->postJson('/api/admin/pos/checkout', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * 5. Sales index reports all orders and calculates real-time summary KPIs.
     */
    public function test_sales_index_returns_paginated_history_and_summary_aggregates(): void
    {
        // Place a POS order
        $order = Order::create([
            'order_number' => 'POS-TEST-' . strtoupper(Str::random(6)),
            'order_source' => 'pos',
            'pos_register_session_id' => $this->session->id,
            'cashier_user_id' => $this->adminUser->id,
            'customer_name' => 'Report Test Buyer',
            'customer_email' => 'buyer.' . Str::random(6) . '@example.com',
            'customer_phone' => '01800000000',
            'subtotal' => 100.00,
            'tax_amount' => 5.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 105.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'delivered',
            'shipping_address' => ['city' => 'Store'],
            'billing_address' => ['city' => 'Store'],
        ]);

        $response = $this->getJson('/api/admin/sales');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'sales' => [
                    'data',
                    'total',
                ],
                'summary' => [
                    'total_sales',
                    'total_transactions',
                    'average_invoice_value',
                    'pos_sales',
                    'online_sales',
                    'total_tax_collected',
                    'total_discount_given',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('summary.total_transactions'));
        $this->assertGreaterThanOrEqual(105.00, (float) $response->json('summary.pos_sales'));
    }

    /**
     * 6. Sales index supports filtering by sales channel/source (POS vs Online).
     */
    public function test_sales_index_filters_by_source(): void
    {
        $response = $this->getJson('/api/admin/sales?source=pos');

        $response->assertStatus(200);
        $data = $response->json('sales.data');

        foreach ($data as $orderRecord) {
            $this->assertEquals('pos', $orderRecord['order_source']);
        }
    }

    /**
     * 7. Sales invoice endpoint retrieves comprehensive order invoice details.
     */
    public function test_sales_invoice_details_endpoint(): void
    {
        $order = Order::create([
            'order_number' => 'INV-TEST-' . strtoupper(Str::random(6)),
            'invoice_number' => 'INV-' . date('Ymd') . '-001',
            'order_source' => 'pos',
            'pos_register_session_id' => $this->session->id,
            'cashier_user_id' => $this->adminUser->id,
            'customer_name' => 'Invoice Customer',
            'customer_email' => 'invoice.' . Str::random(6) . '@example.com',
            'customer_phone' => '01900000000',
            'subtotal' => 200.00,
            'tax_amount' => 10.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 210.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'delivered',
            'shipping_address' => ['city' => 'Store'],
            'billing_address' => ['city' => 'Store'],
        ]);

        $response = $this->getJson('/api/admin/sales/invoice/' . $order->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'invoice_number',
                'order_number',
                'order_source',
                'customer' => [
                    'name',
                    'email',
                    'phone',
                ],
                'terminal',
                'items',
                'financials' => [
                    'subtotal',
                    'total_amount',
                ],
                'company',
            ]);

        $this->assertEquals($order->order_number, $response->json('order_number'));
        $this->assertEquals(210.00, (float) $response->json('financials.total_amount'));
    }
}
