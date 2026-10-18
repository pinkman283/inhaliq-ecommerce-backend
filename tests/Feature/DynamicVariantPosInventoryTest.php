<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosRegister;
use App\Models\PosRegisterSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OrderReturnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicVariantPosInventoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Category $category;
    protected PosRegister $register;
    protected PosRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name' => 'POS Inventory Super Admin',
            'email' => 'pos.inventory.' . Str::random(8) . '@example.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->category = Category::firstOrCreate(
            ['slug' => 'pos-variant-inventory-category'],
            ['name' => 'POS Variant Inventory Category', 'is_active' => true]
        );

        $this->register = PosRegister::firstOrCreate(
            ['code' => 'REG-DYNAMIC-1'],
            ['name' => 'Dynamic POS Register', 'status' => 'open']
        );

        $this->session = PosRegisterSession::create([
            'pos_register_id' => $this->register->id,
            'user_id' => $this->adminUser->id,
            'opened_at' => now(),
            'opening_balance' => 200.00,
            'expected_cash_balance' => 200.00,
            'cash_sales_amount' => 0.00,
            'card_sales_amount' => 0.00,
            'mobile_sales_amount' => 0.00,
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->adminUser, ['admin:access']);
    }

    /**
     * 1. POS sale using an active dynamic variant.
     */
    public function test_pos_sale_using_active_dynamic_variant(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Dynamic T-Shirt ' . Str::random(5),
            'slug' => 'dyn-tshirt-' . Str::random(8),
            'sku' => 'DTS-' . strtoupper(Str::random(6)),
            'price' => 50.00,
            'cost_price' => 25.00,
            'stock_quantity' => 20,
            'is_active' => true,
            'options' => [
                [
                    'name' => 'Material',
                    'values' => ['Cotton', 'Polyester'],
                ],
            ],
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Dynamic T-Shirt - Cotton',
            'sku' => $product->sku . '-COT',
            'barcode' => 'BAR-' . Str::random(8),
            'price' => 75.00,
            'cost_price' => 30.00,
            'stock_quantity' => 10,
            'is_active' => true,
            'variant_attributes' => ['Material' => 'Cotton'],
        ]);

        $initialCashSales = (float) $this->session->cash_sales_amount;

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'customer_name' => 'John Walkin',
            'customer_phone' => '01711111111',
            'payment_method' => 'cash',
            'cash_received' => 150.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201);
        $orderData = $response->json('order');
        $this->assertEquals(150.00, (float) $orderData['total_amount']);

        // Check OrderItem snapshots
        $orderItem = OrderItem::where('order_id', $orderData['id'])->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals($variant->id, $orderItem->variant_id);
        $this->assertEquals('Dynamic T-Shirt - Cotton', $orderItem->variant_name);
        $this->assertEquals(['Material' => 'Cotton'], $orderItem->variant_attributes);
        $this->assertEquals(75.00, (float) $orderItem->unit_price);
        $this->assertEquals(150.00, (float) $orderItem->total_price);

        // Check stock deductions
        $this->assertEquals(8, $variant->fresh()->stock_quantity);
        $this->assertEquals(18, $product->fresh()->stock_quantity);

        // Check register session cash update
        $this->session->refresh();
        $this->assertEquals($initialCashSales + 150.00, (float) $this->session->cash_sales_amount);
    }

    /**
     * 2. POS rejection of an inactive variant for a new sale.
     */
    public function test_pos_rejection_of_inactive_variant_for_new_sale(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Deactivated Item ' . Str::random(5),
            'slug' => 'deact-item-' . Str::random(8),
            'sku' => 'DEA-' . strtoupper(Str::random(6)),
            'price' => 40.00,
            'cost_price' => 20.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $inactiveVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Deactivated Item - Disabled Variant',
            'sku' => $product->sku . '-DIS',
            'price' => 45.00,
            'cost_price' => 22.00,
            'stock_quantity' => 10,
            'is_active' => false, // Inactive
        ]);

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $inactiveVariant->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('inactive', strtolower($response->json('message') ?? json_encode($response->json())));

        // Stock must be intact
        $this->assertEquals(10, $inactiveVariant->fresh()->stock_quantity);
    }

    /**
     * 3. Variant/product ownership validation in POS and PO workflows.
     */
    public function test_pos_and_po_reject_variant_product_ownership_mismatch(): void
    {
        $productA = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product Alpha ' . Str::random(5),
            'slug' => 'prod-alpha-' . Str::random(8),
            'sku' => 'PAL-' . strtoupper(Str::random(6)),
            'price' => 50.00,
            'cost_price' => 20.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $productB = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product Beta ' . Str::random(5),
            'slug' => 'prod-beta-' . Str::random(8),
            'sku' => 'PBE-' . strtoupper(Str::random(6)),
            'price' => 60.00,
            'cost_price' => 30.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variantB = ProductVariant::create([
            'product_id' => $productB->id,
            'name' => 'Product Beta Variant',
            'sku' => $productB->sku . '-VB',
            'price' => 65.00,
            'cost_price' => 32.00,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        // 3a. POS checkout ownership mismatch
        $posPayload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variant_id' => $variantB->id, // Belongs to Product B!
                    'quantity' => 1,
                ],
            ],
        ];

        $posResponse = $this->postJson('/api/admin/pos/checkout', $posPayload);
        $posResponse->assertStatus(422);
        $this->assertStringContainsString('does not belong to product', strtolower($posResponse->json('message') ?? json_encode($posResponse->json())));

        // 3b. Purchase Order ownership mismatch
        $vendor = Vendor::create([
            'vendor_code' => 'VEND-' . strtoupper(Str::random(5)),
            'company_name' => 'Supplier Co ' . Str::random(4),
            'name' => 'Vendor Test ' . Str::random(5),
            'contact_person' => 'Supplier John',
            'email' => 'vendor.' . Str::random(6) . '@example.com',
            'phone' => '01822222222',
            'status' => 'active',
        ]);

        $poPayload = [
            'vendor_id' => $vendor->id,
            'order_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variant_id' => $variantB->id, // Belongs to Product B!
                    'unit_cost' => 20.00,
                    'quantity_ordered' => 5,
                ],
            ],
        ];

        $poResponse = $this->postJson('/api/admin/purchase-orders', $poPayload);
        $poResponse->assertStatus(422);
        $this->assertStringContainsString('does not belong to product', strtolower($poResponse->json('message') ?? json_encode($poResponse->json())));
    }

    /**
     * 4. Direct variant price precedence in POS checkout.
     */
    public function test_pos_uses_authoritative_direct_variant_price_precedence(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Direct Price Item ' . Str::random(5),
            'slug' => 'direct-price-' . Str::random(8),
            'sku' => 'DPR-' . strtoupper(Str::random(6)),
            'price' => 100.00,
            'cost_price' => 50.00,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Direct Price Variant',
            'sku' => $product->sku . '-DIR',
            'price' => 140.00, // Non-null direct price
            'price_modifier' => 10.00, // Should be ignored in favor of direct price 140.00
            'cost_price' => 60.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Client attempts to pass a tampered unit_price: 25.00
        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'cash_received' => 200.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'unit_price' => 25.00, // Tampered client price
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201);
        // Server authoritative total must be 140.00
        $this->assertEquals(140.00, (float) $response->json('order.total_amount'));
    }

    /**
     * 5. Legacy price-modifier fallback in POS checkout.
     */
    public function test_pos_falls_back_to_legacy_price_modifier_when_variant_price_null(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Modifier Item ' . Str::random(5),
            'slug' => 'mod-item-' . Str::random(8),
            'sku' => 'MOD-' . strtoupper(Str::random(6)),
            'price' => 100.00,
            'cost_price' => 40.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Modifier Variant',
            'sku' => $product->sku . '-MOD',
            'price' => null, // Null direct price -> fallback to product.price + price_modifier
            'price_modifier' => 25.00,
            'cost_price' => 45.00,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'cash_received' => 150.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201);
        // Authoritative price: 100.00 + 25.00 = 125.00
        $this->assertEquals(125.00, (float) $response->json('order.total_amount'));
    }

    /**
     * 6. Correct variant stock deduction and parent stock synchronization.
     */
    public function test_correct_variant_stock_deduction_and_parent_stock_synchronization(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Multi Variant Sync Item ' . Str::random(5),
            'slug' => 'multi-sync-' . Str::random(8),
            'sku' => 'MSY-' . strtoupper(Str::random(6)),
            'price' => 50.00,
            'cost_price' => 20.00,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant One',
            'sku' => $product->sku . '-1',
            'price' => 50.00,
            'cost_price' => 20.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $v2 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant Two',
            'sku' => $product->sku . '-2',
            'price' => 50.00,
            'cost_price' => 20.00,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $v1->id,
                    'quantity' => 4,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);
        $response->assertStatus(201);

        // V1 stock was 10 -> now 6
        $this->assertEquals(6, $v1->fresh()->stock_quantity);
        // V2 stock was 5 -> unchanged
        $this->assertEquals(5, $v2->fresh()->stock_quantity);
        // Parent product was 15 -> now 11
        $this->assertEquals(11, $product->fresh()->stock_quantity);

        // Re-synchronizing from variants should maintain exactly 11
        $product->syncStockFromVariants();
        $this->assertEquals(11, $product->fresh()->stock_quantity);
    }

    /**
     * 7. Barcode and SKU lookup for variants in POS product catalog search.
     */
    public function test_barcode_and_sku_lookup_for_variants_in_pos(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Barcode Search Item ' . Str::random(5),
            'slug' => 'barcode-item-' . Str::random(8),
            'sku' => 'PAR-' . strtoupper(Str::random(6)),
            'price' => 80.00,
            'cost_price' => 40.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $uniqueBarcode = 'EAN-' . strtoupper(Str::random(10));
        $uniqueSku = 'VSKU-' . strtoupper(Str::random(8));

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant with Barcode',
            'sku' => $uniqueSku,
            'barcode' => $uniqueBarcode,
            'price' => 85.00,
            'cost_price' => 42.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Search by variant SKU
        $skuResponse = $this->getJson('/api/admin/pos/products?search=' . urlencode($uniqueSku));
        $skuResponse->assertStatus(200);
        $foundBySku = collect($skuResponse->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($foundBySku, 'Product must be found in POS when searching by variant SKU.');

        // Search by variant Barcode
        $barResponse = $this->getJson('/api/admin/pos/products?search=' . urlencode($uniqueBarcode));
        $barResponse->assertStatus(200);
        $foundByBarcode = collect($barResponse->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($foundByBarcode, 'Product must be found in POS when searching by variant Barcode.');
    }

    /**
     * 8. Inventory adjustments targeting the correct variant.
     */
    public function test_inventory_adjustments_target_correct_variant_and_record_movement(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Adjustment Target Item ' . Str::random(5),
            'slug' => 'adj-target-' . Str::random(8),
            'sku' => 'ADJ-' . strtoupper(Str::random(6)),
            'price' => 45.00,
            'cost_price' => 20.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant for Adjustment',
            'sku' => $product->sku . '-VADJ',
            'cost_price' => 22.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Adjust variant stock +8
        $response = $this->postJson("/api/admin/inventory/{$product->id}/adjust", [
            'variant_id' => $variant->id,
            'adjustment' => 8,
            'reason' => 'Cycle Count Inflow',
            'unit_cost' => 22.00,
        ]);

        $response->assertStatus(200);

        // Variant stock should be 18
        $this->assertEquals(18, $variant->fresh()->stock_quantity);
        // Parent product stock should be 18
        $this->assertEquals(18, $product->fresh()->stock_quantity);

        // Check InventoryMovement
        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('movement_type', 'manual_adjustment')
            ->latest('id')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(8, $movement->quantity);
        $this->assertEquals(22.00, (float) $movement->unit_cost);
        $this->assertEquals(18, $movement->balance_after);
    }

    /**
     * 9. Purchase receiving and stock restoration for variants.
     */
    public function test_purchase_receiving_and_stock_restoration_for_variants(): void
    {
        $vendor = Vendor::create([
            'vendor_code' => 'VEND-' . strtoupper(Str::random(5)),
            'company_name' => 'Receiving Supplier Co',
            'name' => 'Receiving Supplier ' . Str::random(5),
            'contact_person' => 'Supplier Dave',
            'email' => 'supplier.' . Str::random(6) . '@example.com',
            'phone' => '01933333333',
            'status' => 'active',
        ]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'PO Receiving Product ' . Str::random(5),
            'slug' => 'po-recv-' . Str::random(8),
            'sku' => 'POR-' . strtoupper(Str::random(6)),
            'price' => 90.00,
            'cost_price' => 40.00,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'PO Receiving Variant',
            'sku' => $product->sku . '-V1',
            'price' => 95.00,
            'cost_price' => 45.00,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        // 1. Create PO
        $poResponse = $this->postJson('/api/admin/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'unit_cost' => 42.50,
                    'quantity_ordered' => 20,
                ],
            ],
        ]);
        $poResponse->assertStatus(201);
        $poId = $poResponse->json('purchase_order.id');

        // 2. Approve PO
        $approveResponse = $this->postJson("/api/admin/purchase-orders/{$poId}/approve");
        $approveResponse->assertStatus(200);

        // 3. Receive Goods via Goods Receipt
        $poItem = PurchaseOrderItem::where('purchase_order_id', $poId)->first();
        $this->assertNotNull($poItem);

        $grResponse = $this->postJson('/api/admin/goods-receipts', [
            'purchase_order_id' => $poId,
            'received_date' => now()->toDateString(),
            'notes' => 'Received first batch of 15 units',
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'quantity_received' => 15,
                ],
            ],
        ]);
        $grResponse->assertStatus(201);

        // Variant & Product physical stock incremented by 15
        $this->assertEquals(15, $variant->fresh()->stock_quantity);
        $this->assertEquals(15, $product->fresh()->stock_quantity);

        // InventoryCostLayer created with variant_id
        $layer = InventoryCostLayer::where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('is_depleted', false)
            ->first();
        $this->assertNotNull($layer);
        $this->assertEquals(15, $layer->remaining_quantity);
        $this->assertEquals(42.50, (float) $layer->unit_cost);

        // InventoryMovement recorded with variant_id
        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('movement_type', 'purchase_received')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(15, $movement->quantity);
        $this->assertEquals(42.50, (float) $movement->unit_cost);
    }

    /**
     * 10. Historical references remaining valid after deactivation.
     */
    public function test_historical_sales_and_movements_remain_valid_after_variant_deactivation(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Historical Variant Product ' . Str::random(5),
            'slug' => 'hist-prod-' . Str::random(8),
            'sku' => 'HIS-' . strtoupper(Str::random(6)),
            'price' => 80.00,
            'cost_price' => 40.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Historical Blue Variant',
            'sku' => $product->sku . '-BLU',
            'price' => 85.00,
            'cost_price' => 42.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Place a POS sale while active
        $saleResponse = $this->postJson('/api/admin/pos/checkout', [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ]);
        $saleResponse->assertStatus(201);
        $orderId = $saleResponse->json('order.id');

        // Deactivate the variant
        $variant->update(['is_active' => false]);
        $this->assertFalse($variant->fresh()->is_active);

        // Invoice retrieval endpoint must remain accessible and render variant details and historical price
        $invoiceResponse = $this->getJson("/api/admin/sales/invoice/{$orderId}");
        $invoiceResponse->assertStatus(200);

        $invoiceItems = $invoiceResponse->json('items');
        $this->assertNotEmpty($invoiceItems);
        $this->assertEquals('Historical Blue Variant', $invoiceItems[0]['variant']);
        $this->assertEquals(85.00, (float) $invoiceItems[0]['unit_price']);

        // Movements endpoint must still return the fulfillment movement for the inactive variant
        $movementsResponse = $this->getJson("/api/admin/inventory-ledger?variant_id={$variant->id}");
        $movementsResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($movementsResponse->json('data')));
    }

    /**
     * 11. Returns preserving the correct variant association and restocking without reactivating inactive variant.
     */
    public function test_returns_preserve_variant_association_and_restock_without_reactivating_inactive_variant(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Returnable Variant Product ' . Str::random(5),
            'slug' => 'ret-prod-' . Str::random(8),
            'sku' => 'RET-' . strtoupper(Str::random(6)),
            'price' => 110.00,
            'cost_price' => 50.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Returnable Variant',
            'sku' => $product->sku . '-VRET',
            'price' => 115.00,
            'cost_price' => 55.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Complete sale
        $saleResponse = $this->postJson('/api/admin/pos/checkout', [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ]);
        $saleResponse->assertStatus(201);
        $orderId = $saleResponse->json('order.id');
        $order = Order::with('items')->findOrFail($orderId);
        $orderItem = $order->items->first();

        // Deactivate variant after the sale
        $variant->update(['is_active' => false]);
        $this->assertEquals(8, $variant->fresh()->stock_quantity);

        // Process Return via OrderReturnService
        $returnService = app(OrderReturnService::class);
        $orderReturn = $returnService->createReturn($order, [
            'return_type' => 'customer_return',
            'return_reason' => 'Customer changed mind',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity_returned' => 2,
                ],
            ],
        ], $this->adminUser);

        $returnItem = $orderReturn->items()->first();
        $this->assertEquals($variant->id, $returnItem->variant_id);

        // QC Inspection and Restock via inspectAndDispose
        $inspectedReturn = $returnService->inspectAndDispose($orderReturn, [
            [
                'id' => $returnItem->id,
                'condition' => 'unopened',
                'disposition' => 'restock_sellable',
                'restocked_quantity' => 2,
            ],
        ], $this->adminUser);

        // Stock restored on both variant and parent product
        $this->assertEquals(10, $variant->fresh()->stock_quantity);
        $this->assertEquals(10, $product->fresh()->stock_quantity);

        // CRITICAL: Inactive variant MUST NOT have been silently reactivated
        $this->assertFalse($variant->fresh()->is_active, 'Restocking a return must not silently reactivate an inactive variant.');

        // InventoryCostLayer created with variant_id
        $costLayer = InventoryCostLayer::where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($costLayer);
        $this->assertEquals(2, $costLayer->remaining_quantity);
    }

    /**
     * 12. Inventory movements and cost layers not being double-counted.
     */
    public function test_inventory_movements_and_cost_layers_not_double_counted(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Valuation Clean Item ' . Str::random(5),
            'slug' => 'val-clean-' . Str::random(8),
            'sku' => 'VAL-' . strtoupper(Str::random(6)),
            'price' => 100.00,
            'cost_price' => 50.00,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        $v1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Valuation V1',
            'sku' => $product->sku . '-V1',
            'price' => 110.00,
            'cost_price' => 50.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $v2 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Valuation V2',
            'sku' => $product->sku . '-V2',
            'price' => 120.00,
            'cost_price' => 60.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Add 1 FIFO layer for V1 ($50 * 10 = $500) and 1 FIFO layer for V2 ($60 * 10 = $600)
        InventoryCostLayer::create([
            'product_id' => $product->id,
            'variant_id' => $v1->id,
            'unit_cost' => 50.00,
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'is_depleted' => false,
        ]);

        InventoryCostLayer::create([
            'product_id' => $product->id,
            'variant_id' => $v2->id,
            'unit_cost' => 60.00,
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'is_depleted' => false,
        ]);

        $valResponse = $this->getJson('/api/admin/inventory-valuation?search=' . urlencode($product->sku));
        $valResponse->assertStatus(200);

        $productRow = collect($valResponse->json('products'))->firstWhere('id', $product->id);
        $this->assertNotNull($productRow);
        // Real FIFO valuation: (10 * 50) + (10 * 60) = 1100.00
        $this->assertEquals(1100.00, (float) $productRow['real_fifo_valuation']);
        // Potential retail value: (10 * 110) + (10 * 120) = 2300.00
        $this->assertEquals(2300.00, (float) $productRow['potential_retail_value']);
    }

    /**
     * 13. Existing legacy color/size POS regression coverage.
     */
    public function test_legacy_color_size_pos_regression_coverage(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Legacy Apparel Item ' . Str::random(5),
            'slug' => 'leg-app-' . Str::random(8),
            'sku' => 'LAP-' . strtoupper(Str::random(6)),
            'price' => 60.00,
            'cost_price' => 30.00,
            'stock_quantity' => 12,
            'is_active' => true,
        ]);

        // Legacy variant with color_name and size
        $legacyVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy Blue / XL',
            'color_name' => 'Navy Blue',
            'color_code' => '#000080',
            'size' => 'XL',
            'sku' => $product->sku . '-NAVY-XL',
            'price' => null, // null direct price
            'price_modifier' => 5.00, // $60 + $5 = $65
            'cost_price' => 30.00,
            'stock_quantity' => 6,
            'is_active' => true,
        ]);

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'cash_received' => 100.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $legacyVariant->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201);
        $this->assertEquals(65.00, (float) $response->json('order.total_amount'));
        $this->assertEquals(5, $legacyVariant->fresh()->stock_quantity);
    }

    /**
     * 14. Simple-product POS regression coverage.
     */
    public function test_simple_product_pos_regression_coverage(): void
    {
        $simpleProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Simple Coffee Mug ' . Str::random(5),
            'slug' => 'mug-' . Str::random(8),
            'sku' => 'MUG-' . strtoupper(Str::random(6)),
            'price' => 15.00,
            'cost_price' => 6.00,
            'stock_quantity' => 30,
            'is_active' => true,
        ]);

        $payload = [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'cash_received' => 50.00,
            'items' => [
                [
                    'product_id' => $simpleProduct->id,
                    'variant_id' => null,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/pos/checkout', $payload);

        $response->assertStatus(201);
        $this->assertEquals(30.00, (float) $response->json('order.total_amount'));
        $this->assertEquals(28, $simpleProduct->fresh()->stock_quantity);

        $orderItem = OrderItem::where('order_id', $response->json('order.id'))->first();
        $this->assertNull($orderItem->variant_id);
    }

    /**
     * 15. Existing accounting and inventory reports retaining historical records and prices.
     */
    public function test_accounting_and_inventory_reports_retain_historical_records_and_prices(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Report Auditing Item ' . Str::random(5),
            'slug' => 'rep-audit-' . Str::random(8),
            'sku' => 'RAU-' . strtoupper(Str::random(6)),
            'price' => 50.00,
            'cost_price' => 25.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Report Auditing Variant',
            'sku' => $product->sku . '-RA',
            'price' => 55.00,
            'cost_price' => 25.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Place sale at 55.00
        $saleResponse = $this->postJson('/api/admin/pos/checkout', [
            'pos_register_session_id' => $this->session->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ]);
        $saleResponse->assertStatus(201);

        // Later: Variant price is increased to 999.00 and variant is deactivated
        $variant->update([
            'price' => 999.00,
            'is_active' => false,
        ]);

        // 15a. Sales reporting summary must reflect historical $55.00, not current $999.00
        $salesResponse = $this->getJson('/api/admin/sales?source=pos');
        $salesResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(55.00, (float) $salesResponse->json('summary.pos_sales'));

        // 15b. Valuation report must remain queryable without throwing or omitting data
        $valResponse = $this->getJson('/api/admin/inventory-valuation?search=' . urlencode($product->sku));
        $valResponse->assertStatus(200);
    }
}
