<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminColorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_color_tester@example.com'],
            [
                'name' => 'Admin Color Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-hardware-cat'],
            [
                'name' => 'Test Hardware Category',
                'description' => 'Test category for colors and variants',
            ]
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    public function test_admin_can_list_colors_with_usage_counts(): void
    {
        $this->actAsAdmin();

        $color = Color::create([
            'name' => 'Obsidian Swatch ' . Str::random(5),
            'hex_code' => '#0F172A',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/admin/colors');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'hex_code',
                'status',
                'variants_count',
            ],
        ]);
    }

    public function test_admin_can_create_color_with_valid_hex(): void
    {
        $this->actAsAdmin();

        $payload = [
            'name' => 'Cyber Neon ' . Str::random(5),
            'hex_code' => '#00FFAA',
            'status' => 'active',
            'sort_order' => 5,
        ];

        $response = $this->postJson('/api/admin/colors', $payload);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => $payload['name'],
            'hex_code' => '#00FFAA',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('colors', ['name' => $payload['name']]);
    }

    public function test_invalid_hex_is_rejected_on_color_creation(): void
    {
        $this->actAsAdmin();

        // 1. Missing hash
        $res1 = $this->postJson('/api/admin/colors', [
            'name' => 'Bad Hex 1 ' . Str::random(5),
            'hex_code' => '00FFAA',
        ]);
        $res1->assertStatus(422)->assertJsonValidationErrors(['hex_code']);

        // 2. Invalid non-hex characters
        $res2 = $this->postJson('/api/admin/colors', [
            'name' => 'Bad Hex 2 ' . Str::random(5),
            'hex_code' => '#GGGGGG',
        ]);
        $res2->assertStatus(422)->assertJsonValidationErrors(['hex_code']);

        // 3. Invalid length
        $res3 = $this->postJson('/api/admin/colors', [
            'name' => 'Bad Hex 3 ' . Str::random(5),
            'hex_code' => '#12345',
        ]);
        $res3->assertStatus(422)->assertJsonValidationErrors(['hex_code']);
    }

    public function test_admin_can_update_color(): void
    {
        $this->actAsAdmin();

        $color = Color::create([
            'name' => 'Color To Update ' . Str::random(5),
            'hex_code' => '#112233',
            'status' => 'active',
        ]);

        $response = $this->putJson("/api/admin/colors/{$color->id}", [
            'name' => 'Color Updated ' . Str::random(5),
            'hex_code' => '#445566',
            'status' => 'inactive',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'hex_code' => '#445566',
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('colors', [
            'id' => $color->id,
            'hex_code' => '#445566',
            'status' => 'inactive',
        ]);
    }

    public function test_admin_can_toggle_color_status(): void
    {
        $this->actAsAdmin();

        $color = Color::create([
            'name' => 'Toggle Color ' . Str::random(5),
            'hex_code' => '#123456',
            'status' => 'active',
        ]);

        $res1 = $this->patchJson("/api/admin/colors/{$color->id}/status");
        $res1->assertStatus(200);
        $this->assertEquals('inactive', $color->fresh()->status);

        $res2 = $this->patchJson("/api/admin/colors/{$color->id}/status");
        $res2->assertStatus(200);
        $this->assertEquals('active', $color->fresh()->status);
    }

    public function test_safe_color_can_be_deleted(): void
    {
        $this->actAsAdmin();

        $color = Color::create([
            'name' => 'Safe Color To Delete ' . Str::random(5),
            'hex_code' => '#AABBCC',
            'status' => 'inactive',
        ]);

        $response = $this->deleteJson("/api/admin/colors/{$color->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('colors', ['id' => $color->id]);
    }

    public function test_color_deletion_blocked_when_product_variants_reference_it(): void
    {
        $this->actAsAdmin();

        $color = Color::create([
            'name' => 'Protected Studio Gray ' . Str::random(5),
            'hex_code' => '#64748B',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Hardware Headphone ' . Str::random(5),
            'slug' => 'hard-head-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 399.00,
            'stock_quantity' => 10,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Protected Variant',
            'color_name' => $color->name,
            'color_hex' => $color->hex_code,
            'stock_quantity' => 10,
        ]);

        $response = $this->deleteJson("/api/admin/colors/{$color->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('conflicts.product_variants', 1);
        $this->assertDatabaseHas('colors', ['id' => $color->id]);
    }

    public function test_variant_reconciliation_preserves_existing_ids_updates_in_place_and_deletes_removed(): void
    {
        $this->actAsAdmin();

        $product = Product::create([
            'name' => 'Keyboard Model ' . Str::random(5),
            'slug' => 'kb-model-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 150.00,
            'stock_quantity' => 20,
        ]);

        $var1 = $product->variants()->create([
            'name' => 'Linear Switch / Black',
            'size' => 'Linear',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'stock_quantity' => 10,
            'price_modifier' => 0.00,
            'sku' => 'KB-LIN-1',
        ]);

        $var2 = $product->variants()->create([
            'name' => 'Tactile Switch / White',
            'size' => 'Tactile',
            'color_name' => 'White',
            'color_hex' => '#FFFFFF',
            'stock_quantity' => 10,
            'price_modifier' => 10.00,
            'sku' => 'KB-TAC-2',
        ]);

        $originalVar1Id = $var1->id;
        $originalVar2Id = $var2->id;

        // Perform product update:
        // - Modify var1 (preserve ID, change stock & price_modifier)
        // - Remove var2 (omit from variants)
        // - Add new var3 (no ID provided)
        $updatePayload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'images' => [
                ['image_url' => 'https://example.com/keyboard.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $originalVar1Id,
                    'name' => 'Linear Switch / Black (Updated)',
                    'size' => 'Linear Cream',
                    'color_name' => 'Obsidian Black',
                    'color_hex' => '#0F172A',
                    'stock_quantity' => 25,
                    'price_modifier' => 5.00,
                ],
                [
                    // New variant without ID
                    'name' => 'Clicky Switch / Blue',
                    'size' => 'Clicky',
                    'color_name' => 'Royal Blue',
                    'color_hex' => '#1D4ED8',
                    'stock_quantity' => 15,
                    'price_modifier' => 15.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $updatePayload);
        $response->assertStatus(200);

        // Verify var1 preserved its exact same ID
        $reconciledVar1 = ProductVariant::find($originalVar1Id);
        $this->assertNotNull($reconciledVar1, 'Original variant 1 ID must remain in database');
        $this->assertEquals('Linear Switch / Black (Updated)', $reconciledVar1->name);
        $this->assertEquals('Linear Cream', $reconciledVar1->size);
        $this->assertEquals('Obsidian Black', $reconciledVar1->color_name);
        $this->assertEquals(25, $reconciledVar1->stock_quantity);
        $this->assertEquals(5.00, $reconciledVar1->price_modifier);
        $this->assertEquals('KB-LIN-1', $reconciledVar1->sku, 'Original variant SKU must be preserved');

        // Verify var2 was safely deactivated to preserve historical order references
        $this->assertDatabaseHas('product_variants', ['id' => $originalVar2Id, 'is_active' => false]);

        // Verify new variant was created with a new ID
        $newVariant = ProductVariant::where('product_id', $product->id)
            ->where('name', 'Clicky Switch / Blue')
            ->first();
        $this->assertNotNull($newVariant);
        $this->assertNotEquals($originalVar1Id, $newVariant->id);
        $this->assertEquals(15, $newVariant->stock_quantity);

        // Verify product total stock sync: 25 + 15 = 40
        $this->assertEquals(40, $product->fresh()->stock_quantity);
    }

    public function test_order_item_variant_relationship_is_preserved_across_product_updates(): void
    {
        $this->actAsAdmin();

        $product = Product::create([
            'name' => 'Smartwatch Pro ' . Str::random(5),
            'slug' => 'watch-pro-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 499.00,
            'stock_quantity' => 10,
        ]);

        $variant = $product->variants()->create([
            'name' => 'Titanium / 49mm',
            'size' => '49mm',
            'color_name' => 'Titanium',
            'color_hex' => '#94A3B8',
            'stock_quantity' => 10,
            'price_modifier' => 50.00,
            'sku' => 'WATCH-49-TIT',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-' . strtoupper(Str::random(6)),
            'user_id' => $this->admin->id,
            'customer_name' => 'Admin Customer',
            'customer_phone' => '01711111111',
            'customer_email' => 'admin@example.com',
            'shipping_address' => '123 Tech Street',
            'subtotal' => 549.00,
            'total_amount' => 549.00,
            'order_status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'product_sku' => $variant->sku,
            'unit_price' => 549.00,
            'quantity' => 1,
            'total_price' => 549.00,
        ]);

        $this->assertEquals($variant->id, $orderItem->variant_id);

        // Update product while keeping the variant
        $updatePayload = [
            'name' => $product->name . ' (v2)',
            'category_id' => $product->category_id,
            'price' => 519.00,
            'images' => [
                ['image_url' => 'https://example.com/watch.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $variant->id,
                    'name' => 'Titanium / 49mm (Restocked)',
                    'size' => '49mm',
                    'color_name' => 'Titanium Gray',
                    'color_hex' => '#64748B',
                    'stock_quantity' => 20,
                    'price_modifier' => 50.00,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $updatePayload);
        $response->assertStatus(200);

        // Crucial Assertion: order_item must still point to the exact same variant_id!
        $refreshedItem = $orderItem->fresh();
        $this->assertNotNull($refreshedItem->variant_id, 'variant_id on order_item must NOT be nullified by product update');
        $this->assertEquals($variant->id, $refreshedItem->variant_id);
    }

    public function test_cross_product_variant_id_cannot_be_tampered(): void
    {
        $this->actAsAdmin();

        $productA = Product::create([
            'name' => 'Product A ' . Str::random(5),
            'slug' => 'prod-a-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 100.00,
            'stock_quantity' => 10,
        ]);

        $variantA = $productA->variants()->create([
            'name' => 'Variant A',
            'stock_quantity' => 10,
        ]);

        $productB = Product::create([
            'name' => 'Product B ' . Str::random(5),
            'slug' => 'prod-b-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 200.00,
            'stock_quantity' => 10,
        ]);

        $variantB = $productB->variants()->create([
            'name' => 'Variant B',
            'stock_quantity' => 10,
        ]);

        // Attempt to update Product A with Variant B's ID
        $tamperedPayload = [
            'name' => $productA->name,
            'category_id' => $productA->category_id,
            'price' => $productA->price,
            'images' => [
                ['image_url' => 'https://example.com/proda.jpg', 'is_primary' => true],
            ],
            'variants' => [
                [
                    'id' => $variantB->id, // Belongs to Product B!
                    'name' => 'Hijacked Variant',
                    'stock_quantity' => 50,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$productA->id}", $tamperedPayload);

        // Must reject cross-product variant modification with HTTP 422
        $response->assertStatus(422);

        // Verify Variant B was NOT hijacked or modified
        $freshVariantB = $variantB->fresh();
        $this->assertEquals('Variant B', $freshVariantB->name);
        $this->assertEquals($productB->id, $freshVariantB->product_id);
    }
}
