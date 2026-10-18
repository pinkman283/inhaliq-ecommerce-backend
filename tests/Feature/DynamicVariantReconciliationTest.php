<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicVariantReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_recon_tester@example.com'],
            [
                'name' => 'Admin Reconciliation Tester',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'test-recon-hardware-cat'],
            [
                'name' => 'Recon Test Hardware Category',
                'description' => 'Test category for dynamic variant reconciliation tests',
            ]
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function createDynamicProduct(array $overrides = []): Product
    {
        $options = [
            [
                'name' => 'Flavor',
                'values' => ['Arctic Mint', 'Mango Freeze'],
            ],
            [
                'name' => 'Capacity',
                'values' => ['2ml', '4ml'],
            ],
        ];

        $product = Product::create(array_merge([
            'name' => 'Dynamic Device ' . Str::random(5),
            'slug' => 'dynamic-device-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 100.00,
            'stock_quantity' => 0,
            'description' => 'Dynamic options hardware test product.',
            'options' => $options,
            'is_active' => true,
        ], $overrides));

        $product->images()->create([
            'image_url' => 'https://example.com/gallery-1.jpg',
            'alt_text' => 'Primary Gallery',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        return $product;
    }

    /**
     * Test 1: Valid dynamic-options product creation.
     */
    public function test_1_valid_dynamic_options_product_creation(): void
    {
        $this->actAsAdmin();

        $payload = [
            'name' => 'Aether Pod Kit ' . Str::random(4),
            'category_id' => $this->category->id,
            'price' => 49.99,
            'stock_quantity' => 0,
            'description' => 'Advanced vapor kit with dynamic options.',
            'images' => [
                ['image_url' => 'https://example.com/aether-pod.jpg', 'is_primary' => true],
            ],
            'options' => [
                ['name' => 'Flavor', 'values' => ['Arctic Mint', 'Mango Freeze']],
                ['name' => 'Nicotine Strength', 'values' => ['10mg', '20mg']],
            ],
            'variants' => [
                [
                    'name' => 'Mint / 10mg',
                    'stock_quantity' => 10,
                    'price' => 49.99,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Nicotine Strength' => '10mg'],
                ],
                [
                    'name' => 'Mint / 20mg',
                    'stock_quantity' => 15,
                    'price' => 54.99,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Nicotine Strength' => '20mg'],
                ],
                [
                    'name' => 'Mango / 10mg',
                    'stock_quantity' => 20,
                    'price' => 49.99,
                    'attributes' => ['Flavor' => 'Mango Freeze', 'Nicotine Strength' => '10mg'],
                ],
                [
                    'name' => 'Mango / 20mg',
                    'stock_quantity' => 25,
                    'price' => 54.99,
                    'attributes' => ['Flavor' => 'Mango Freeze', 'Nicotine Strength' => '20mg'],
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/products', $payload);

        $response->assertStatus(201);
        $productId = $response->json('product.id');

        $product = Product::with('variants')->findOrFail($productId);
        $this->assertTrue($product->hasOptions());
        $this->assertCount(2, $product->options);
        $this->assertEquals('Flavor', $product->options[0]['name']);
        $this->assertCount(4, $product->variants);

        // Parent warehouse stock synced from active variants (10 + 15 + 20 + 25 = 70)
        $this->assertEquals(70, $product->stock_quantity);

        $firstVariant = $product->variants->firstWhere('name', 'Mint / 10mg');
        $this->assertNotNull($firstVariant);
        $this->assertEquals(49.99, $firstVariant->price);
        $this->assertEquals('Arctic Mint', $firstVariant->attributes['Flavor']);
        $this->assertEquals('10mg', $firstVariant->attributes['Nicotine Strength']);
        $this->assertTrue($firstVariant->is_active);
    }

    /**
     * Test 2: Invalid option-group structures are rejected with HTTP 422.
     */
    public function test_2_invalid_option_group_structures_are_rejected(): void
    {
        $this->actAsAdmin();

        // 2a. Empty option group name
        $payload1 = [
            'name' => 'Device Bad Options',
            'category_id' => $this->category->id,
            'price' => 39.00,
            'stock_quantity' => 0,
            'description' => 'Test invalid option structure',
            'images' => [['image_url' => 'https://example.com/img.jpg', 'is_primary' => true]],
            'options' => [
                ['name' => '   ', 'values' => ['Value A']],
            ],
            'variants' => [],
        ];
        $res1 = $this->postJson('/api/admin/products', $payload1);
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['options.0.name']);

        // 2b. Empty values array
        $payload2 = [
            'name' => 'Device Bad Values',
            'category_id' => $this->category->id,
            'price' => 39.00,
            'stock_quantity' => 0,
            'description' => 'Test empty values array',
            'images' => [['image_url' => 'https://example.com/img.jpg', 'is_primary' => true]],
            'options' => [
                ['name' => 'Color', 'values' => []],
            ],
            'variants' => [],
        ];
        $res2 = $this->postJson('/api/admin/products', $payload2);
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['options.0.values']);

        // 2c. Whitespace-only string in values
        $payload3 = [
            'name' => 'Device Whitespace Value',
            'category_id' => $this->category->id,
            'price' => 39.00,
            'stock_quantity' => 0,
            'description' => 'Test whitespace value',
            'images' => [['image_url' => 'https://example.com/img.jpg', 'is_primary' => true]],
            'options' => [
                ['name' => 'Color', 'values' => ['   ']],
            ],
            'variants' => [],
        ];
        $res3 = $this->postJson('/api/admin/products', $payload3);
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['options']);
    }

    /**
     * Test 3: Duplicate option names and duplicate values within the same group are rejected.
     */
    public function test_3_duplicate_option_names_and_values_are_rejected(): void
    {
        $this->actAsAdmin();

        // 3a. Duplicate option group names (case-insensitive)
        $payloadDupGroup = [
            'name' => 'Device Dup Group',
            'category_id' => $this->category->id,
            'price' => 39.00,
            'stock_quantity' => 0,
            'description' => 'Test duplicate option groups',
            'images' => [['image_url' => 'https://example.com/img.jpg', 'is_primary' => true]],
            'options' => [
                ['name' => 'Flavor', 'values' => ['Mint']],
                ['name' => ' flavor ', 'values' => ['Berry']],
            ],
            'variants' => [],
        ];
        $res1 = $this->postJson('/api/admin/products', $payloadDupGroup);
        $res1->assertStatus(422);
        $this->assertStringContainsString('Duplicate option group name', $res1->json('errors.options.0'));

        // 3b. Duplicate values within the same group (case-insensitive)
        $payloadDupVal = [
            'name' => 'Device Dup Val',
            'category_id' => $this->category->id,
            'price' => 39.00,
            'stock_quantity' => 0,
            'description' => 'Test duplicate option values',
            'images' => [['image_url' => 'https://example.com/img.jpg', 'is_primary' => true]],
            'options' => [
                ['name' => 'Flavor', 'values' => ['Mint', '  mint  ']],
            ],
            'variants' => [],
        ];
        $res2 = $this->postJson('/api/admin/products', $payloadDupVal);
        $res2->assertStatus(422);
        $this->assertStringContainsString('Duplicate value', $res2->json('errors.options.0'));
    }

    /**
     * Test 4: Missing, extra, or invalid variant attributes are rejected.
     */
    public function test_4_missing_extra_or_invalid_variant_attributes_are_rejected(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        // 4a. Missing attribute (Flavor provided, Capacity omitted)
        $payloadMissing = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Partial Variant',
                    'stock_quantity' => 10,
                    'attributes' => ['Flavor' => 'Arctic Mint'], // Missing Capacity
                ],
            ],
        ];
        $res1 = $this->putJson("/api/admin/products/{$product->id}", $payloadMissing);
        $res1->assertStatus(422);
        $this->assertStringContainsString('Missing attribute', $res1->json('errors.variants.0'));

        // 4b. Extra undeclared attribute
        $payloadExtra = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Extra Variant',
                    'stock_quantity' => 10,
                    'attributes' => [
                        'Flavor' => 'Arctic Mint',
                        'Capacity' => '2ml',
                        'LED Color' => 'Blue', // Not in declared options
                    ],
                ],
            ],
        ];
        $res2 = $this->putJson("/api/admin/products/{$product->id}", $payloadExtra);
        $res2->assertStatus(422);
        $this->assertStringContainsString('Unknown attribute', $res2->json('errors.variants.0'));

        // 4c. Invalid value not in declared values
        $payloadInvalidVal = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Invalid Val Variant',
                    'stock_quantity' => 10,
                    'attributes' => [
                        'Flavor' => 'Chocolate Cream', // Not allowed
                        'Capacity' => '2ml',
                    ],
                ],
            ],
        ];
        $res3 = $this->putJson("/api/admin/products/{$product->id}", $payloadInvalidVal);
        $res3->assertStatus(422);
        $this->assertStringContainsString('Invalid value', $res3->json('errors.variants.0'));
    }

    /**
     * Test 5: Duplicate variant combinations within the same submitted payload are rejected.
     */
    public function test_5_duplicate_variant_combinations_are_rejected(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Combo 1',
                    'stock_quantity' => 5,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
                [
                    // Duplicate combination with different key order, case, and whitespace
                    'name' => 'Combo 2 Duplicate',
                    'stock_quantity' => 10,
                    'attributes' => ['capacity' => ' 2ML ', 'flavor' => ' arctic mint '],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variants']);
        $this->assertStringContainsString('Duplicate variant combination', $response->json('message'));
    }

    /**
     * Test 6: Direct variant price persistence and non-negative validation.
     */
    public function test_6_direct_variant_price_persistence_and_validation(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct(['price' => 100.00]);

        // 6a. Negative price is rejected
        $payloadNegative = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Negative Price Variant',
                    'stock_quantity' => 5,
                    'price' => -15.00,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
            ],
        ];
        $resNegative = $this->putJson("/api/admin/products/{$product->id}", $payloadNegative);
        $resNegative->assertStatus(422);
        $resNegative->assertJsonValidationErrors(['variants.0.price']);

        // 6b. Valid direct price (e.g. 89.95) persists and takes precedence
        $payloadValid = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Discounted Pod',
                    'stock_quantity' => 12,
                    'price' => 89.95,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
                [
                    'name' => 'Null Price Pod',
                    'stock_quantity' => 8,
                    'price' => null,
                    'price_modifier' => 20.00,
                    'attributes' => ['Flavor' => 'Mango Freeze', 'Capacity' => '4ml'],
                ],
            ],
        ];
        $resValid = $this->putJson("/api/admin/products/{$product->id}", $payloadValid);
        $resValid->assertStatus(200);

        $v1 = ProductVariant::where('product_id', $product->id)->where('name', 'Discounted Pod')->first();
        $this->assertNotNull($v1);
        $this->assertEquals(89.95, $v1->price);
        $this->assertEquals(89.95, $v1->effective_price);

        $v2 = ProductVariant::where('product_id', $product->id)->where('name', 'Null Price Pod')->first();
        $this->assertNotNull($v2);
        $this->assertNull($v2->price);
        $this->assertEquals(120.00, $v2->effective_price); // 100 base + 20 modifier
    }

    /**
     * Test 7: Legacy price-modifier compatibility.
     */
    public function test_7_legacy_price_modifier_compatibility(): void
    {
        $this->actAsAdmin();

        $product = Product::create([
            'name' => 'Legacy Device ' . Str::random(4),
            'slug' => 'legacy-device-' . Str::random(8),
            'category_id' => $this->category->id,
            'price' => 50.00,
            'stock_quantity' => 0,
            'description' => 'Legacy color size device.',
            'images' => [
                ['image_url' => 'https://example.com/legacy.jpg', 'is_primary' => true],
            ],
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 50.00,
            'variants' => [
                [
                    'name' => 'Standard Color',
                    'color_name' => 'Black',
                    'stock_quantity' => 10,
                    'price_modifier' => 0.00,
                    'price' => null,
                ],
                [
                    'name' => 'Premium Color',
                    'color_name' => 'Gold',
                    'stock_quantity' => 5,
                    'price_modifier' => 15.00,
                    'price' => null,
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $vGold = ProductVariant::where('product_id', $product->id)->where('color_name', 'Gold')->first();
        $this->assertEquals(65.00, $vGold->effective_price);
    }

    /**
     * Test 8: Rejecting a gallery image belonging to another product.
     */
    public function test_8_rejecting_gallery_image_belonging_to_another_product(): void
    {
        $this->actAsAdmin();

        $productA = $this->createDynamicProduct(['name' => 'Product Alpha']);
        $productB = $this->createDynamicProduct(['name' => 'Product Beta']);

        $imageB = $productB->primaryImage;
        $this->assertNotNull($imageB);

        // Attempt to associate product A's variant with product B's image
        $payload = [
            'name' => $productA->name,
            'category_id' => $productA->category_id,
            'price' => $productA->price,
            'variants' => [
                [
                    'name' => 'Variant With Stolen Image',
                    'stock_quantity' => 10,
                    'image_id' => $imageB->id, // Belongs to Product B!
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$productA->id}", $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variants']);
        $this->assertStringContainsString('does not belong to this product', $response->json('errors.variants.0'));
    }

    /**
     * Test 9: Preserving IDs for matching combinations during updates.
     */
    public function test_9_preserving_ids_for_matching_combinations_during_updates(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $var1 = $product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'price' => 19.99,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => true,
        ]);

        $var2 = $product->variants()->create([
            'name' => 'Mango / 4ml',
            'stock_quantity' => 15,
            'price' => 24.99,
            'attributes' => ['Flavor' => 'Mango Freeze', 'Capacity' => '4ml'],
            'is_active' => true,
        ]);

        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'id' => $var1->id,
                    'name' => 'Mint / 2ml Updated',
                    'stock_quantity' => 25,
                    'price' => 21.99,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
                [
                    'id' => $var2->id,
                    'name' => 'Mango / 4ml Updated',
                    'stock_quantity' => 30,
                    'price' => 26.99,
                    'attributes' => ['Flavor' => 'Mango Freeze', 'Capacity' => '4ml'],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $fresh1 = ProductVariant::find($var1->id);
        $fresh2 = ProductVariant::find($var2->id);

        $this->assertNotNull($fresh1);
        $this->assertNotNull($fresh2);
        $this->assertEquals($var1->id, $fresh1->id);
        $this->assertEquals($var2->id, $fresh2->id);
        $this->assertEquals(25, $fresh1->stock_quantity);
        $this->assertEquals(30, $fresh2->stock_quantity);
        $this->assertEquals(21.99, $fresh1->price);
    }

    /**
     * Test 10: Deactivating omitted variants without physically deleting them.
     */
    public function test_10_deactivating_omitted_variants_without_physically_deleting_them(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $keptVar = $product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => true,
        ]);

        $omittedVar = $product->variants()->create([
            'name' => 'Mango / 4ml (To be deactivated)',
            'stock_quantity' => 20,
            'attributes' => ['Flavor' => 'Mango Freeze', 'Capacity' => '4ml'],
            'is_active' => true,
        ]);

        // Submit update containing ONLY keptVar
        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'id' => $keptVar->id,
                    'name' => 'Mint / 2ml Kept',
                    'stock_quantity' => 12,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        // Verify omittedVar was NOT physically deleted
        $this->assertDatabaseHas('product_variants', [
            'id' => $omittedVar->id,
            'is_active' => false,
        ]);

        // Verify parent stock only sums active variants (12, not 12 + 20)
        $this->assertEquals(12, $product->fresh()->stock_quantity);

        // Verify keptVar is active
        $this->assertTrue(ProductVariant::find($keptVar->id)->is_active);
    }

    /**
     * Test 11: Ensuring omitted variants key does not deactivate existing variants.
     */
    public function test_11_ensuring_omitted_variants_does_not_deactivate_existing_variants(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $var1 = $product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => true,
        ]);

        $var2 = $product->variants()->create([
            'name' => 'Mango / 4ml',
            'stock_quantity' => 15,
            'attributes' => ['Flavor' => 'Mango Freeze', 'Capacity' => '4ml'],
            'is_active' => true,
        ]);

        $product->syncStockFromVariants();
        $this->assertEquals(25, $product->fresh()->stock_quantity);

        // Send metadata update WITHOUT 'variants' key in the payload
        $payload = [
            'name' => 'Updated Product Name Only',
            'description' => 'Updated description without touching variants.',
            'stock_quantity' => 999, // Should be ignored because active variants exist
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        // Both variants must remain active
        $this->assertTrue(ProductVariant::find($var1->id)->is_active);
        $this->assertTrue(ProductVariant::find($var2->id)->is_active);

        // Parent warehouse stock remains 25, protected from overwrite
        $this->assertEquals(25, $product->fresh()->stock_quantity);
    }

    /**
     * Test 12: Explicitly empty variants follows mode-transition semantics (converting to simple product).
     */
    public function test_12_explicitly_empty_variants_follows_mode_transition_semantics(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $var1 = $product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => true,
        ]);

        // Explicitly empty variants array: transition to simple product!
        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 79.00,
            'stock_quantity' => 45,
            'options' => null,
            'variants' => [],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        $freshProduct = $product->fresh();
        $this->assertFalse($freshProduct->hasOptions());
        $this->assertNull($freshProduct->options);
        $this->assertEquals(45, $freshProduct->stock_quantity);

        // Existing variant must be soft-deactivated, NOT deleted
        $this->assertDatabaseHas('product_variants', [
            'id' => $var1->id,
            'is_active' => false,
        ]);
    }

    /**
     * Test 13: Failed reconciliation rolls back database changes.
     */
    public function test_13_failed_reconciliation_rolls_back_database_changes(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct(['name' => 'Original Stable Name']);
        $productB = $this->createDynamicProduct(['name' => 'Other Product']);

        $imageB = $productB->primaryImage;

        // Attempt update with changed name, but invalid cross-product variant image
        $payload = [
            'name' => 'Mutated Name That Should Rollback',
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Bad Image Variant',
                    'stock_quantity' => 10,
                    'image_id' => $imageB->id, // Cross-product image triggers rollback!
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(422);

        // Product name must NOT have changed in the database
        $this->assertEquals('Original Stable Name', $product->fresh()->name);
    }

    /**
     * Test 14: Simple-product and legacy color/size regression coverage.
     */
    public function test_14_simple_product_and_legacy_color_size_regression_coverage(): void
    {
        $this->actAsAdmin();

        // 14a. Create simple product (no options, no variants)
        $simplePayload = [
            'name' => 'Simple Vapor Pen ' . Str::random(4),
            'category_id' => $this->category->id,
            'price' => 29.99,
            'stock_quantity' => 50,
            'description' => 'Simple hardware device without variants.',
            'images' => [
                ['image_url' => 'https://example.com/simple.jpg', 'is_primary' => true],
            ],
        ];

        $createRes = $this->postJson('/api/admin/products', $simplePayload);
        $createRes->assertStatus(201);
        $simpleProduct = Product::findOrFail($createRes->json('product.id'));
        $this->assertFalse($simpleProduct->hasOptions());
        $this->assertCount(0, $simpleProduct->variants);
        $this->assertEquals(50, $simpleProduct->stock_quantity);

        // 14b. Convert simple product into dynamic-options product
        $convertPayload = [
            'name' => $simpleProduct->name,
            'category_id' => $simpleProduct->category_id,
            'price' => $simpleProduct->price,
            'options' => [
                ['name' => 'Color', 'values' => ['Midnight Black', 'Silver']],
            ],
            'variants' => [
                [
                    'name' => 'Black Edition',
                    'stock_quantity' => 20,
                    'attributes' => ['Color' => 'Midnight Black'],
                ],
                [
                    'name' => 'Silver Edition',
                    'stock_quantity' => 30,
                    'attributes' => ['Color' => 'Silver'],
                ],
            ],
        ];

        $convertRes = $this->putJson("/api/admin/products/{$simpleProduct->id}", $convertPayload);
        $convertRes->assertStatus(200);

        $convertedProduct = $simpleProduct->fresh();
        $this->assertTrue($convertedProduct->hasOptions());
        $this->assertCount(2, $convertedProduct->variants);
        $this->assertEquals(50, $convertedProduct->stock_quantity);
    }

    /**
     * Test 15: Safe behavior when a historical inactive combination is encountered again.
     */
    public function test_15_safe_behavior_when_historical_inactive_combination_encountered_again(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        // Previously deactivated variant
        $inactiveVar = $product->variants()->create([
            'name' => 'Mint / 2ml (Historical Inactive)',
            'stock_quantity' => 0,
            'price' => 19.99,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => false,
            'sku' => 'INHALIQ-MINT-2ML-HIST',
        ]);

        // Admin submits an update that includes this combination WITHOUT passing 'id'
        $payload = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'variants' => [
                [
                    'name' => 'Mint / 2ml (Reactivated)',
                    'stock_quantity' => 25,
                    'price' => 22.50,
                    'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
                ],
            ],
        ];

        $response = $this->putJson("/api/admin/products/{$product->id}", $payload);
        $response->assertStatus(200);

        // Must reuse the existing record, preserving its ID and SKU!
        $reusedVar = ProductVariant::find($inactiveVar->id);
        $this->assertNotNull($reusedVar);
        $this->assertTrue($reusedVar->is_active);
        $this->assertEquals(25, $reusedVar->stock_quantity);
        $this->assertEquals(22.50, $reusedVar->price);
        $this->assertEquals('INHALIQ-MINT-2ML-HIST', $reusedVar->sku);

        // No duplicate record created
        $allMint2ml = ProductVariant::where('product_id', $product->id)->get();
        $this->assertCount(1, $allMint2ml);
    }

    /**
     * Test 16: Deterministic handling of option-group and value renaming.
     */
    public function test_16_deterministic_handling_of_option_group_and_value_renaming(): void
    {
        $this->actAsAdmin();

        $product = $this->createDynamicProduct();

        $existingVar = $product->variants()->create([
            'name' => 'Mint / 2ml',
            'stock_quantity' => 10,
            'attributes' => ['Flavor' => 'Arctic Mint', 'Capacity' => '2ml'],
            'is_active' => true,
        ]);

        // Scenario 16A: Renaming WITH ID specified -> updates record in place
        $renamePayloadWithId = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'options' => [
                ['name' => 'Aroma', 'values' => ['Peppermint Ice']],
                ['name' => 'Volume', 'values' => ['2.5ml']],
            ],
            'variants' => [
                [
                    'id' => $existingVar->id,
                    'name' => 'Peppermint / 2.5ml',
                    'stock_quantity' => 15,
                    'attributes' => ['Aroma' => 'Peppermint Ice', 'Volume' => '2.5ml'],
                ],
            ],
        ];

        $resWithId = $this->putJson("/api/admin/products/{$product->id}", $renamePayloadWithId);
        $resWithId->assertStatus(200);

        $updatedVar = ProductVariant::find($existingVar->id);
        $this->assertEquals($existingVar->id, $updatedVar->id);
        $this->assertEquals('Peppermint Ice', $updatedVar->attributes['Aroma']);
        $this->assertEquals('2.5ml', $updatedVar->attributes['Volume']);

        // Scenario 16B: Renaming WITHOUT ID specified -> deactivates old combination, creates new combination deterministically without guessing
        $renamePayloadWithoutId = [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'options' => [
                ['name' => 'Blend', 'values' => ['Spearmint Frost']],
                ['name' => 'Size', 'values' => ['3ml']],
            ],
            'variants' => [
                [
                    // No 'id' provided: canonical key 'blend=spearmint frost|size=3ml' does NOT match 'aroma=peppermint ice|volume=2.5ml'
                    'name' => 'Spearmint / 3ml',
                    'stock_quantity' => 18,
                    'attributes' => ['Blend' => 'Spearmint Frost', 'Size' => '3ml'],
                ],
            ],
        ];

        $resWithoutId = $this->putJson("/api/admin/products/{$product->id}", $renamePayloadWithoutId);
        $resWithoutId->assertStatus(200);

        // Previous variant is safely deactivated (not deleted)
        $this->assertDatabaseHas('product_variants', [
            'id' => $existingVar->id,
            'is_active' => false,
        ]);

        // A new variant is created for the new combination
        $newVar = ProductVariant::where('product_id', $product->id)
            ->where('name', 'Spearmint / 3ml')
            ->first();
        $this->assertNotNull($newVar);
        $this->assertNotEquals($existingVar->id, $newVar->id);
        $this->assertTrue($newVar->is_active);
    }
}
