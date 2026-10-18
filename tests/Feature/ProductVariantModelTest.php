<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantModelTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-model-cat-' . Str::random(6)],
            ['name' => 'Model Test Category', 'description' => 'Test category for model tests']
        );

        $this->product = Product::create([
            'name' => 'Model Device ' . Str::random(5),
            'slug' => 'model-device-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 100.00,
            'stock_quantity' => 0,
            'description' => 'Test hardware device for model testing.',
        ]);
    }

    /**
     * Test 1: JSON/array casts for product options and variant attributes.
     */
    public function test_product_options_and_variant_attributes_cast_to_array(): void
    {
        $optionsData = [
            ['name' => 'Flavor', 'values' => ['Arctic Mint', 'Mango Freeze']],
            ['name' => 'Capacity', 'values' => ['2ml', '4ml']],
        ];

        $this->product->update(['options' => $optionsData]);
        $freshProduct = $this->product->fresh();

        $this->assertIsArray($freshProduct->options);
        $this->assertCount(2, $freshProduct->options);
        $this->assertEquals('Flavor', $freshProduct->options[0]['name']);
        $this->assertTrue($freshProduct->hasOptions());
        $this->assertTrue($freshProduct->has_options);

        $variant = $this->product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'attributes' => [
                'Flavor' => 'Arctic Mint',
                'Capacity' => '2ml',
            ],
        ]);

        $freshVariant = ProductVariant::find($variant->id);
        $this->assertIsArray($freshVariant->attributes);
        $this->assertEquals('Arctic Mint', $freshVariant->attributes['Flavor']);
        $this->assertEquals('2ml', $freshVariant->attributes['Capacity']);
    }

    /**
     * Test 2: Direct variant price takes precedence when non-null.
     */
    public function test_direct_variant_price_takes_precedence_when_non_null(): void
    {
        // Variant priced below parent product (e.g. 79.99 vs parent 100.00)
        $variant = $this->product->variants()->create([
            'name' => 'Discounted Edition',
            'stock_quantity' => 5,
            'price' => 79.99,
            'price_modifier' => 15.00, // Legacy modifier should be ignored when direct price exists
        ]);

        $freshVariant = ProductVariant::find($variant->id);
        $this->assertEquals(79.99, $freshVariant->effective_price);
        $this->assertEquals(79.99, $freshVariant->effectivePrice());
    }

    /**
     * Test 3: Legacy effective price fallback when direct price is null.
     */
    public function test_legacy_effective_price_fallback_when_direct_price_is_null(): void
    {
        // Variant with positive modifier (100 + 25 = 125)
        $variantWithMod = $this->product->variants()->create([
            'name' => 'Extended Capacity',
            'stock_quantity' => 5,
            'price' => null,
            'price_modifier' => 25.00,
        ]);

        $this->assertEquals(125.00, $variantWithMod->fresh()->effective_price);
        $this->assertEquals(125.00, $variantWithMod->fresh()->effectivePrice());

        // Variant with zero modifier (100 + 0 = 100)
        $variantStandard = $this->product->variants()->create([
            'name' => 'Standard Edition',
            'stock_quantity' => 5,
            'price' => null,
            'price_modifier' => 0.00,
        ]);

        $this->assertEquals(100.00, $variantStandard->fresh()->effective_price);
    }

    /**
     * Test 4: Variant image relationship and inverse image->variants relationship.
     */
    public function test_variant_image_relationship_and_inverse_relationship(): void
    {
        $image = $this->product->images()->create([
            'image_url' => 'https://example.com/gallery-mint.jpg',
            'alt_text' => 'Mint Gallery Image',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $variant = $this->product->variants()->create([
            'name' => 'Mint Pod',
            'stock_quantity' => 10,
            'image_id' => $image->id,
        ]);

        $freshVariant = ProductVariant::find($variant->id);
        $this->assertNotNull($freshVariant->image);
        $this->assertEquals($image->id, $freshVariant->image->id);
        $this->assertEquals('https://example.com/gallery-mint.jpg', $freshVariant->image->image_url);

        // Test inverse relationship
        $freshImage = ProductImage::find($image->id);
        $this->assertTrue($freshImage->variants->contains('id', $variant->id));
        $this->assertCount(1, $freshImage->variants);
    }

    /**
     * Test 5: Active scope excludes explicitly inactive variants.
     */
    public function test_active_scope_excludes_inactive_variants(): void
    {
        $activeVar = $this->product->variants()->create([
            'name' => 'Active Option',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $inactiveVar = $this->product->variants()->create([
            'name' => 'Discontinued Option',
            'stock_quantity' => 0,
            'is_active' => false,
        ]);

        $activeVariants = ProductVariant::active()
            ->where('product_id', $this->product->id)
            ->get();

        $this->assertCount(1, $activeVariants);
        $this->assertTrue($activeVariants->contains('id', $activeVar->id));
        $this->assertFalse($activeVariants->contains('id', $inactiveVar->id));

        // Unscoped query returns all variants (active and inactive)
        $allVariants = ProductVariant::where('product_id', $this->product->id)->get();
        $this->assertCount(2, $allVariants);
    }

    /**
     * Test 6: Legacy variant compatibility when dynamic fields are null or default.
     */
    public function test_legacy_variant_compatibility_with_null_dynamic_fields(): void
    {
        // Legacy variant created with only color/size
        $legacyVariant = $this->product->variants()->create([
            'name' => 'Classic Black / 49mm',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'size' => '49mm',
            'stock_quantity' => 20,
            'price_modifier' => 10.00,
        ]);

        $fresh = ProductVariant::find($legacyVariant->id);
        $this->assertNull($fresh->attributes);
        $this->assertNull($fresh->price);
        $this->assertNull($fresh->image_id);
        $this->assertTrue($fresh->is_active); // Default true
        $this->assertEquals(110.00, $fresh->effective_price); // 100 base + 10 modifier
    }

    /**
     * Test 7: Order item variant_attributes cast to array.
     */
    public function test_order_item_variant_attributes_cast_to_array(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'customer_order_tester@example.com'],
            ['name' => 'Customer Tester', 'password' => bcrypt('password123'), 'role' => 'customer']
        );

        $order = Order::create([
            'order_number' => 'ORD-TEST-' . Str::upper(Str::random(8)),
            'user_id' => $user->id,
            'customer_name' => 'Customer Tester',
            'customer_email' => 'customer_order_tester@example.com',
            'customer_phone' => '01700000000',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'shipping_address' => ['address' => '123 Test St'],
            'billing_address' => ['address' => '123 Test St'],
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'variant_name' => 'Mint / 2ml',
            'unit_price' => 79.99,
            'quantity' => 1,
            'total_price' => 79.99,
            'variant_attributes' => [
                'Flavor' => 'Arctic Mint',
                'Capacity' => '2ml',
            ],
        ]);

        $freshItem = OrderItem::find($orderItem->id);
        $this->assertIsArray($freshItem->variant_attributes);
        $this->assertEquals('Arctic Mint', $freshItem->variant_attributes['Flavor']);
        $this->assertEquals('2ml', $freshItem->variant_attributes['Capacity']);
    }
}
