<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicDynamicCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;
    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-dyn-catalog-category'],
            [
                'name' => 'Dynamic Catalog Category',
                'description' => 'Category for dynamic catalog tests',
                'is_featured' => true,
                'display_order' => 1,
            ]
        );

        $this->brand = Brand::firstOrCreate(
            ['slug' => 'test-dyn-catalog-brand'],
            [
                'name' => 'Dynamic Catalog Brand',
                'description' => 'Brand for dynamic catalog tests',
                'is_featured' => true,
                'display_order' => 1,
            ]
        );
    }

    private function createDynamicProduct(array $overrides = []): Product
    {
        $unique = Str::random(8);

        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'name' => 'Dynamic Test Product ' . $unique,
            'slug' => 'dyn-product-' . strtolower($unique),
            'brand' => $this->brand->name,
            'sku' => 'DYN-PROD-' . strtoupper($unique),
            'short_description' => 'Dynamic options product test.',
            'description' => 'Full product description with dynamic variants.',
            'price' => 100.00,
            'cost_price' => 50.00,
            'compare_at_price' => 120.00,
            'stock_quantity' => 10,
            'is_featured' => true,
            'is_new_arrival' => true,
            'is_best_seller' => true,
            'is_active' => true,
            'options' => [
                [
                    'name' => 'Flavor',
                    'values' => ['Mango', 'Mint'],
                ],
                [
                    'name' => 'Nicotine Strength',
                    'values' => ['20mg', '50mg'],
                ],
            ],
        ], $overrides));
    }

    /**
     * 1. Dynamic options in public product detail responses.
     */
    public function test_dynamic_options_exposed_in_product_detail_response(): void
    {
        $product = $this->createDynamicProduct();

        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Mango / 20mg',
            'sku' => 'DYN-MNG-20-' . Str::random(4),
            'price' => 105.00,
            'stock_quantity' => 5,
            'attributes' => ['Flavor' => 'Mango', 'Nicotine Strength' => '20mg'],
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.has_options', true);

        $options = $res->json('product.options');
        $this->assertIsArray($options);
        $this->assertCount(2, $options);
        $this->assertEquals('Flavor', $options[0]['name']);
        $this->assertEquals(['Mango', 'Mint'], $options[0]['values']);
        $this->assertEquals('Nicotine Strength', $options[1]['name']);
        $this->assertEquals(['20mg', '50mg'], $options[1]['values']);
    }

    /**
     * 2. Dynamic options in public product listing responses.
     */
    public function test_dynamic_options_exposed_in_product_listing_response(): void
    {
        $product = $this->createDynamicProduct();

        $res = $this->getJson('/api/products?search=' . urlencode($product->sku));

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'price', 'options', 'has_options', 'variants'],
                ],
            ]);

        $item = collect($res->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item);
        $this->assertTrue($item['has_options']);
        $this->assertIsArray($item['options']);
        $this->assertEquals('Flavor', $item['options'][0]['name']);
    }

    /**
     * 3. Correct JSON types for options and attributes.
     */
    public function test_options_and_attributes_serialize_as_proper_json_types_not_strings(): void
    {
        $product = $this->createDynamicProduct();

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Mint / 50mg',
            'sku' => 'DYN-MNT-50-' . Str::random(4),
            'price' => 115.50,
            'stock_quantity' => 8,
            'attributes' => [
                'Flavor' => 'Mint',
                'Nicotine Strength' => '50mg',
                'Capacity' => '2ml',
            ],
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);

        // Assert options is an array
        $options = $res->json('product.options');
        $this->assertIsArray($options);
        $this->assertIsNotString($options);

        // Assert attributes is an associative dictionary/object
        $variants = $res->json('product.variants');
        $this->assertIsArray($variants);
        $firstVariant = $variants[0];

        $this->assertIsArray($firstVariant['attributes']);
        $this->assertIsNotString($firstVariant['attributes']);
        $this->assertEquals('Mint', $firstVariant['attributes']['Flavor']);
        $this->assertEquals('50mg', $firstVariant['attributes']['Nicotine Strength']);
        $this->assertEquals('2ml', $firstVariant['attributes']['Capacity']);

        // Assert boolean and float types
        $this->assertIsBool($firstVariant['is_active']);
        $this->assertTrue($firstVariant['is_active']);
        $this->assertIsFloat($firstVariant['price']);
        $this->assertEquals(115.50, $firstVariant['price']);
        $this->assertIsFloat($firstVariant['effective_price']);
        $this->assertEquals(115.50, $firstVariant['effective_price']);
    }

    /**
     * 4. Direct variant price serialization.
     */
    public function test_direct_variant_price_is_authoritative_when_set(): void
    {
        $product = $this->createDynamicProduct(['price' => 100.00]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Direct Price Variant',
            'sku' => 'DIR-PRICE-' . Str::random(4),
            'price' => 129.99,
            'price_modifier' => 15.00, // Should be ignored when direct price is set
            'stock_quantity' => 10,
            'attributes' => ['Flavor' => 'Mango'],
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        $this->assertEquals(129.99, $vData['price']);
        $this->assertEquals(129.99, $vData['effective_price']);
        $this->assertEquals(15.00, $vData['price_modifier']);
    }

    /**
     * 5. Legacy effective-price fallback serialization.
     */
    public function test_legacy_effective_price_fallback_when_direct_price_is_null(): void
    {
        $product = $this->createDynamicProduct(['price' => 100.00]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Fallback Price Variant',
            'sku' => 'FALLBACK-' . Str::random(4),
            'price' => null,
            'price_modifier' => 25.50,
            'stock_quantity' => 10,
            'color_name' => 'Silver',
            'size' => 'Medium',
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        $this->assertNull($vData['price']);
        $this->assertEquals(25.50, $vData['price_modifier']);
        // Effective price = base price (100.00) + modifier (25.50) = 125.50
        $this->assertEquals(125.50, $vData['effective_price']);
    }

    /**
     * 6. Inactive variants excluded from public listing and detail responses.
     */
    public function test_inactive_variants_excluded_from_public_detail_and_listing(): void
    {
        $product = $this->createDynamicProduct();

        $activeVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Active Variant',
            'sku' => 'ACT-' . Str::random(4),
            'price' => 100.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $inactiveVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Deactivated Variant',
            'sku' => 'INACT-' . Str::random(4),
            'price' => 80.00,
            'stock_quantity' => 0,
            'is_active' => false,
        ]);

        // 1. Check Product Detail
        $detailRes = $this->getJson('/api/products/' . $product->slug);
        $detailRes->assertStatus(200);

        $variants = $detailRes->json('product.variants');
        $this->assertCount(1, $variants);
        $this->assertEquals($activeVariant->id, $variants[0]['id']);
        $this->assertEquals('Active Variant', $variants[0]['name']);

        $variantIds = collect($variants)->pluck('id')->all();
        $this->assertNotContains($inactiveVariant->id, $variantIds);

        // 2. Check Product Listing
        $listRes = $this->getJson('/api/products?search=' . urlencode($product->sku));
        $listRes->assertStatus(200);

        $listItem = collect($listRes->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($listItem);
        $listVariants = $listItem['variants'];
        $this->assertCount(1, $listVariants);
        $this->assertEquals($activeVariant->id, $listVariants[0]['id']);

        // 3. Search by inactive variant SKU must not match
        $inactiveSearchRes = $this->getJson('/api/products?search=' . urlencode($inactiveVariant->sku));
        $inactiveSearchRes->assertStatus(200);
        $inactiveMatched = collect($inactiveSearchRes->json('data'))->firstWhere('id', $product->id);
        $this->assertNull($inactiveMatched, 'Product should not match search when only an inactive variant matches');
    }

    /**
     * 7. Active legacy variants remain visible.
     */
    public function test_active_legacy_variants_remain_visible_with_backward_compatibility(): void
    {
        $product = $this->createDynamicProduct(['options' => null]);

        $legacyVariant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Red / XL',
            'sku' => 'LEG-RED-XL-' . Str::random(4),
            'size' => 'XL',
            'color_name' => 'Red',
            'color_hex' => '#FF0000',
            'price_modifier' => 10.00,
            'stock_quantity' => 15,
            'barcode' => 'BAR123456789',
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        $this->assertEquals($legacyVariant->id, $vData['id']);
        $this->assertEquals('Red / XL', $vData['name']);
        $this->assertEquals('XL', $vData['size']);
        $this->assertEquals('Red', $vData['color_name']);
        $this->assertEquals('#FF0000', $vData['color_hex']);
        $this->assertEquals($legacyVariant->sku, $vData['sku']);
        $this->assertEquals('BAR123456789', $vData['barcode']);
        $this->assertEquals(10.00, $vData['price_modifier']);
        $this->assertEquals(110.00, $vData['effective_price']);
        $this->assertEquals(15, $vData['stock_quantity']);
        $this->assertTrue($vData['is_active']);
    }

    /**
     * 8. Simple products without variants remain compatible.
     */
    public function test_simple_products_without_variants_remain_compatible(): void
    {
        $simpleProduct = $this->createDynamicProduct([
            'options' => null,
            'price' => 45.00,
            'stock_quantity' => 20,
        ]);

        $res = $this->getJson('/api/products/' . $simpleProduct->slug);

        $res->assertStatus(200);
        $this->assertEquals($simpleProduct->id, $res->json('product.id'));
        $this->assertEquals(45.00, $res->json('product.price'));
        $this->assertEquals(20, $res->json('product.stock_quantity'));
        $this->assertNull($res->json('product.options'));
        $this->assertFalse($res->json('product.has_options'));
        $this->assertIsArray($res->json('product.variants'));
        $this->assertEmpty($res->json('product.variants'));
    }

    /**
     * 9. Variant image resolution using image_id.
     */
    public function test_variant_image_resolution_using_explicit_image_id(): void
    {
        $product = $this->createDynamicProduct();

        $imageA = ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/img-a.jpg',
            'is_primary' => true,
            'display_order' => 1,
        ]);

        $imageB = ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/img-b-variant.jpg',
            'is_primary' => false,
            'display_order' => 2,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant With Explicit Image',
            'sku' => 'IMG-VAR-' . Str::random(4),
            'image_id' => $imageB->id,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        $this->assertEquals($imageB->id, $vData['image_id']);
        $this->assertEquals('https://example.com/gallery/img-b-variant.jpg', $vData['image_url']);
        $this->assertEquals('https://example.com/gallery/img-b-variant.jpg', $vData['image']);
    }

    /**
     * 10. Legacy color-based image fallback.
     */
    public function test_legacy_color_based_image_fallback(): void
    {
        $product = $this->createDynamicProduct();

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/img-primary.jpg',
            'is_primary' => true,
            'display_order' => 1,
        ]);

        $blueImage = ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/img-royal-blue.jpg',
            'color_name' => 'Royal Blue',
            'is_primary' => false,
            'display_order' => 2,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Royal Blue T-Shirt',
            'sku' => 'CLR-VAR-' . Str::random(4),
            'color_name' => 'royal blue', // Case-insensitive match
            'image_id' => null,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        $this->assertNull($vData['image_id']);
        $this->assertEquals('https://example.com/gallery/img-royal-blue.jpg', $vData['image_url']);
    }

    /**
     * 11. Primary-image and first-image fallbacks.
     */
    public function test_primary_image_and_first_image_fallbacks(): void
    {
        $product = $this->createDynamicProduct();

        $primaryImage = ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/main-primary.jpg',
            'is_primary' => true,
            'display_order' => 1,
        ]);

        $otherImage = ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/gallery/other-gallery.jpg',
            'is_primary' => false,
            'display_order' => 2,
        ]);

        // Variant without explicit image_id and no matching color
        $variantA = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant With No Match',
            'sku' => 'NO-IMG-' . Str::random(4),
            'color_name' => 'Neon Green',
            'image_id' => null,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);
        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        // Should fall back to primary image
        $this->assertEquals('https://example.com/gallery/main-primary.jpg', $vData['image_url']);

        // Now test first-image fallback when no primary image exists
        $primaryImage->update(['is_primary' => false]);

        $res2 = $this->getJson('/api/products/' . $product->slug);
        $res2->assertStatus(200);
        $vData2 = $res2->json('product.variants.0');

        // Should fall back to first gallery image
        $this->assertEquals('https://example.com/gallery/main-primary.jpg', $vData2['image_url']);
    }

    /**
     * 11b. Cross-product image protection: alien image_id falls back safely.
     */
    public function test_alien_image_id_from_another_product_is_ignored_and_falls_back(): void
    {
        $productA = $this->createDynamicProduct(['name' => 'Product A']);
        $productB = $this->createDynamicProduct(['name' => 'Product B']);

        $imageA = ProductImage::create([
            'product_id' => $productA->id,
            'image_url' => 'https://example.com/gallery/product-a-primary.jpg',
            'is_primary' => true,
        ]);

        $imageB = ProductImage::create([
            'product_id' => $productB->id,
            'image_url' => 'https://example.com/gallery/product-b-secret.jpg',
            'is_primary' => true,
        ]);

        // Variant of Product A maliciously or erroneously set to Product B's image_id
        $variant = ProductVariant::create([
            'product_id' => $productA->id,
            'name' => 'Variant with Alien Image',
            'sku' => 'ALIEN-' . Str::random(4),
            'image_id' => $imageB->id,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $productA->slug);
        $res->assertStatus(200);
        $vData = $res->json('product.variants.0');

        // It MUST NOT expose Product B's image; it should fall back to Product A's primary image
        $this->assertEquals('https://example.com/gallery/product-a-primary.jpg', $vData['image_url']);
    }

    /**
     * 12. Existing product response fields and response envelopes remain compatible.
     */
    public function test_existing_product_response_fields_and_envelopes_remain_compatible(): void
    {
        $product = $this->createDynamicProduct();

        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Compatibility Variant',
            'sku' => 'COMPAT-' . Str::random(4),
            'size' => 'L',
            'color_name' => 'Black',
            'color_hex' => '#000000',
            'stock_quantity' => 12,
            'price_modifier' => 5.00,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/products/' . $product->slug);

        $res->assertStatus(200)
            ->assertJsonStructure([
                'product' => [
                    'id',
                    'category_id',
                    'subcategory_id',
                    'brand_id',
                    'name',
                    'slug',
                    'brand',
                    'sku',
                    'short_description',
                    'description',
                    'price',
                    'compare_at_price',
                    'stock_quantity',
                    'is_featured',
                    'is_new_arrival',
                    'is_best_seller',
                    'is_active',
                    'meta_title',
                    'meta_description',
                    'rating_average',
                    'review_count',
                    'tags',
                    'specifications',
                    'options',
                    'has_options',
                    'created_at',
                    'updated_at',
                    'image',
                    'primary_image',
                    'primaryImage',
                    'images',
                    'category',
                    'subcategory',
                    'brandRelation',
                    'variants' => [
                        '*' => [
                            'id',
                            'product_id',
                            'name',
                            'size',
                            'color_name',
                            'color_hex',
                            'sku',
                            'barcode',
                            'price_modifier',
                            'price',
                            'effective_price',
                            'stock_quantity',
                            'attributes',
                            'image_id',
                            'image_url',
                            'image',
                            'is_active',
                        ],
                    ],
                    'reviews',
                ],
                'related',
            ]);

        // Security sanity check: cost_price MUST NEVER be leaked in public API
        $rawJson = $res->getContent();
        $this->assertStringNotContainsString('cost_price', $rawJson);
    }
}
