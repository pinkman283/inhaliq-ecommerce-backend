<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicVariantImportExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $customer;
    private Category $category;
    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_dyn_import@example.com'],
            [
                'name' => 'Admin Dynamic Importer',
                'password' => bcrypt('secret123'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->customer = User::firstOrCreate(
            ['email' => 'customer_dyn_import@example.com'],
            [
                'name' => 'Customer Dynamic Importer',
                'password' => bcrypt('secret123'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );

        $this->category = Category::firstOrCreate(
            ['slug' => 'dyn-import-cat'],
            ['name' => 'Dynamic Hardware', 'description' => 'Category for dynamic variants test']
        );

        $this->brand = Brand::firstOrCreate(
            ['slug' => 'dyn-import-brand'],
            ['name' => 'AetherTech']
        );
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
    }

    private function buildCsvContent(array $headers, array $rows): string
    {
        $fp = fopen('php://memory', 'r+');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $content = stream_get_contents($fp);
        fclose($fp);
        return $content;
    }

    private function createUploadedFile(string $content, string $filename = 'products.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    private function parseCsv(string $content): array
    {
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        if (str_starts_with($content, $bom)) {
            $content = substr($content, strlen($bom));
        }

        $lines = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($row = fgetcsv($stream)) !== false) {
            $lines[] = $row;
        }
        fclose($stream);
        return $lines;
    }

    private function getStandardHeaders(bool $includeOptions = false): array
    {
        $headers = [
            'ID',
            'SKU',
            'Name',
            'Slug',
            'Description',
            'Short Description',
            'Category',
            'Brand',
            'Price',
            'Compare At Price',
            'Cost Price',
            'Stock Quantity',
            'Is Active',
            'Is Featured',
            'Is New Arrival',
            'Is Best Seller',
            'Primary Image URL',
            'Additional Image URLs',
            'Variant Summary',
            'Created At',
            'Updated At',
        ];

        if ($includeOptions) {
            $headers[] = 'Options';
        }

        return $headers;
    }

    private function buildRow(array $data): array
    {
        return [
            $data['ID'] ?? '',
            $data['SKU'] ?? '',
            $data['Name'] ?? 'Test Product',
            $data['Slug'] ?? '',
            $data['Description'] ?? '',
            $data['Short Description'] ?? '',
            $data['Category'] ?? $this->category->name,
            $data['Brand'] ?? $this->brand->name,
            $data['Price'] ?? '99.99',
            $data['Compare At Price'] ?? '',
            $data['Cost Price'] ?? '',
            $data['Stock Quantity'] ?? '0',
            $data['Is Active'] ?? 'true',
            $data['Is Featured'] ?? 'false',
            $data['Is New Arrival'] ?? 'false',
            $data['Is Best Seller'] ?? 'false',
            $data['Primary Image URL'] ?? '',
            $data['Additional Image URLs'] ?? '',
            $data['Variant Summary'] ?? '',
            $data['Created At'] ?? '',
            $data['Updated At'] ?? '',
        ];
    }

    private function buildRowWithOptions(array $data, string $options): array
    {
        $row = $this->buildRow($data);
        $row[] = $options;
        return $row;
    }

    /**
     * 1. Importing a dynamic product with multiple option groups.
     * Supports both delimited string ("Color: Black, White | Storage: 128GB, 256GB") and JSON array.
     */
    public function test_1_importing_dynamic_product_with_multiple_option_groups(): void
    {
        $this->actAsAdmin();

        $sku = 'DYN-OPT-' . strtoupper(Str::random(6));
        $optionsStr = 'Color: Space Gray, Silver | Storage: 256GB, 512GB';

        $variants = [
            [
                'sku' => $sku . '-SG-256',
                'name' => 'Space Gray / 256GB',
                'price' => 1099.00,
                'stock_quantity' => 20,
                'is_active' => true,
                'attributes' => ['Color' => 'Space Gray', 'Storage' => '256GB'],
            ],
            [
                'sku' => $sku . '-SLV-512',
                'name' => 'Silver / 512GB',
                'price' => 1299.00,
                'stock_quantity' => 15,
                'is_active' => true,
                'attributes' => ['Color' => 'Silver', 'Storage' => '512GB'],
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(true), [
            $this->buildRowWithOptions([
                'SKU' => $sku,
                'Name' => 'MacBook Pro Aether',
                'Price' => '1099.00',
                'Variant Summary' => json_encode($variants),
            ], $optionsStr)
        ]);

        $file = $this->createUploadedFile($csv);
        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $valRes->assertOk()->assertJsonPath('can_commit', true);

        $token = $valRes->json('import_token');
        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertOk()->assertJsonPath('status', 'success');

        $product = Product::where('sku', $sku)->with('variants')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->hasOptions());
        $this->assertCount(2, $product->options);
        $this->assertEquals('Color', $product->options[0]['name']);
        $this->assertEquals(['Space Gray', 'Silver'], $product->options[0]['values']);
        $this->assertEquals('Storage', $product->options[1]['name']);
        $this->assertEquals(['256GB', '512GB'], $product->options[1]['values']);
        $this->assertCount(2, $product->variants);
    }

    /**
     * 2. Importing structured attributes and valid variant combinations.
     */
    public function test_2_importing_structured_attributes_and_valid_variant_combinations(): void
    {
        $this->actAsAdmin();

        $sku = 'DYN-STRUCT-' . strtoupper(Str::random(6));
        $variants = [
            [
                'sku' => $sku . '-M-BLK',
                'attributes' => ['Size' => 'M', 'Color' => 'Black'],
                'price' => 59.99,
                'stock_quantity' => 30,
            ],
            [
                'sku' => $sku . '-L-BLK',
                'attributes' => ['Size' => 'L', 'Color' => 'Black'],
                'price' => 64.99,
                'stock_quantity' => 25,
            ],
            [
                'sku' => $sku . '-M-WHT',
                'attributes' => ['Size' => 'M', 'Color' => 'White'],
                'price' => 59.99,
                'stock_quantity' => 10,
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => $sku,
                'Name' => 'Structured Attributes Shirt',
                'Price' => '59.99',
                'Variant Summary' => json_encode($variants),
            ])
        ]);

        $file = $this->createUploadedFile($csv);
        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $valRes->assertOk()->assertJsonPath('can_commit', true);

        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $valRes->json('import_token')]);
        $commitRes->assertOk();

        $product = Product::where('sku', $sku)->with('variants')->first();
        $this->assertNotNull($product);
        $this->assertCount(3, $product->variants);

        $v1 = $product->variants()->where('sku', $sku . '-M-BLK')->first();
        $this->assertNotNull($v1);
        $this->assertEquals(['Size' => 'M', 'Color' => 'Black'], $v1->attributes);
        $this->assertEquals(['Size' => 'M', 'Color' => 'Black'], $v1->variant_attributes);
        $this->assertEquals('M / Black', $v1->name);
    }

    /**
     * 3. Direct variant price precedence and legacy price_modifier fallback.
     */
    public function test_3_direct_variant_price_precedence_and_legacy_modifier_fallback(): void
    {
        $this->actAsAdmin();

        $sku = 'DYN-PRICE-' . strtoupper(Str::random(6));
        $variants = [
            // Direct price set ($79.99) with price_modifier ($20.00). Direct price must take precedence.
            [
                'sku' => $sku . '-DIRECT',
                'name' => 'Direct Price Variant',
                'price' => 79.99,
                'price_modifier' => 20.00,
                'attributes' => ['Edition' => 'Deluxe'],
            ],
            // Direct price omitted (null), price_modifier ($15.00) on base $50.00. Effective price = $65.00.
            [
                'sku' => $sku . '-LEGACY',
                'name' => 'Legacy Modifier Variant',
                'price' => null,
                'price_modifier' => 15.00,
                'attributes' => ['Edition' => 'Standard'],
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => $sku,
                'Name' => 'Pricing Test Prod',
                'Price' => '50.00',
                'Variant Summary' => json_encode($variants),
            ])
        ]);

        $file = $this->createUploadedFile($csv);
        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $vDirect = ProductVariant::where('sku', $sku . '-DIRECT')->first();
        $this->assertNotNull($vDirect);
        $this->assertEquals(79.99, $vDirect->price);
        $this->assertEquals(20.00, $vDirect->price_modifier);
        $this->assertEquals(79.99, $vDirect->effective_price);

        $vLegacy = ProductVariant::where('sku', $sku . '-LEGACY')->first();
        $this->assertNotNull($vLegacy);
        $this->assertNull($vLegacy->price);
        $this->assertEquals(15.00, $vLegacy->price_modifier);
        $this->assertEquals(65.00, $vLegacy->effective_price); // 50.00 base + 15.00 modifier
    }

    /**
     * 4. Importing variant stock and active status.
     */
    public function test_4_importing_variant_stock_and_active_status(): void
    {
        $this->actAsAdmin();

        $sku = 'DYN-STOCK-' . strtoupper(Str::random(6));
        $variants = [
            [
                'sku' => $sku . '-ACTIVE',
                'name' => 'Active In-Stock',
                'stock_quantity' => 45,
                'is_active' => true,
                'attributes' => ['Grade' => 'A'],
            ],
            [
                'sku' => $sku . '-INACTIVE',
                'name' => 'Inactive Stock',
                'stock_quantity' => 10,
                'is_active' => false,
                'attributes' => ['Grade' => 'B'],
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => $sku,
                'Name' => 'Stock & Status Product',
                'Price' => '100.00',
                'Variant Summary' => json_encode($variants),
            ])
        ]);

        $file = $this->createUploadedFile($csv);
        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $vActive = ProductVariant::where('sku', $sku . '-ACTIVE')->first();
        $this->assertEquals(45, $vActive->stock_quantity);
        $this->assertTrue($vActive->is_active);

        $vInactive = ProductVariant::where('sku', $sku . '-INACTIVE')->first();
        $this->assertEquals(10, $vInactive->stock_quantity);
        $this->assertFalse($vInactive->is_active);

        // Product stock derives from ACTIVE variants only (45)
        $product = Product::where('sku', $sku)->first();
        $this->assertEquals(45, $product->stock_quantity);
    }

    /**
     * 5. Rejecting malformed or duplicate combinations.
     */
    public function test_5_rejecting_malformed_or_duplicate_combinations(): void
    {
        $this->actAsAdmin();

        // 5a. Duplicate dynamic variant combinations
        $dupVariants = [
            [
                'sku' => 'V-DUP-01',
                'name' => 'Option 1',
                'attributes' => ['Color' => 'Titanium', 'Storage' => '1TB'],
            ],
            [
                'sku' => 'V-DUP-02',
                'name' => 'Option 2',
                'attributes' => ['Color' => 'Titanium', 'Storage' => '1TB'], // Duplicate combination!
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => 'DUP-DYN-SKU',
                'Name' => 'Duplicate Combo Product',
                'Price' => '100.00',
                'Variant Summary' => json_encode($dupVariants),
            ])
        ]);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv)]);
        $res->assertOk()->assertJsonPath('can_commit', false);
        $this->assertStringContainsString('Duplicate variant combination', $res->json('rows.0.errors.0'));

        // 5b. Missing declared option attribute in variant
        $optionsStr = 'Color: Red, Blue | Size: S, M';
        $missingAttrVariants = [
            [
                'sku' => 'V-MISSING-ATTR',
                'name' => 'Only Color Provided',
                'attributes' => ['Color' => 'Red'], // Missing 'Size' attribute!
            ]
        ];

        $csv2 = $this->buildCsvContent($this->getStandardHeaders(true), [
            $this->buildRowWithOptions([
                'SKU' => 'MISSING-ATTR-SKU',
                'Name' => 'Missing Attr Product',
                'Price' => '100.00',
                'Variant Summary' => json_encode($missingAttrVariants),
            ], $optionsStr)
        ]);

        $res2 = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv2)]);
        $res2->assertOk()->assertJsonPath('can_commit', false);
        $this->assertStringContainsString("missing attribute 'Size'", $res2->json('rows.0.errors.0'));
    }

    /**
     * 6. Rejecting variant/product ownership mismatches.
     */
    public function test_6_rejecting_variant_product_ownership_mismatches(): void
    {
        $this->actAsAdmin();

        $prod1 = Product::create([
            'sku' => 'OWN-PROD-01',
            'name' => 'Owner Product 1',
            'slug' => 'own-prod-01',
            'category_id' => $this->category->id,
            'price' => 100.00,
            'is_active' => true,
        ]);

        $prod2 = Product::create([
            'sku' => 'OWN-PROD-02',
            'name' => 'Owner Product 2',
            'slug' => 'own-prod-02',
            'category_id' => $this->category->id,
            'price' => 200.00,
            'is_active' => true,
        ]);

        // Variant belonging to Product 1
        $v1 = $prod1->variants()->create([
            'sku' => 'OWN-VAR-01',
            'name' => 'Prod 1 Variant',
            'stock_quantity' => 5,
        ]);

        // CSV tries to update Product 2, but passes Variant ID belonging to Product 1!
        $mismatchVariants = [
            [
                'id' => $v1->id, // Mismatch! Does not belong to OWN-PROD-02
                'sku' => 'OWN-VAR-01',
                'name' => 'Illegal Ownership Claim',
            ]
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'ID' => (string)$prod2->id,
                'SKU' => $prod2->sku,
                'Name' => $prod2->name,
                'Price' => '200.00',
                'Variant Summary' => json_encode($mismatchVariants),
            ])
        ]);

        $res = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv)]);
        $res->assertOk()->assertJsonPath('can_commit', false);
        $this->assertStringContainsString("does not belong to product '{$prod2->sku}'", $res->json('rows.0.errors.0'));
    }

    /**
     * 7. Ensuring partial imports do not deactivate omitted variants.
     */
    public function test_7_partial_imports_do_not_deactivate_omitted_variants(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'sku' => 'PARTIAL-SKU-01',
            'name' => 'Partial Import Base',
            'slug' => 'partial-import-base',
            'category_id' => $this->category->id,
            'price' => 150.00,
            'is_active' => true,
        ]);

        $v1 = $prod->variants()->create(['sku' => 'PARTIAL-V1', 'name' => 'Variant 1', 'stock_quantity' => 10, 'is_active' => true]);
        $v2 = $prod->variants()->create(['sku' => 'PARTIAL-V2', 'name' => 'Variant 2', 'stock_quantity' => 15, 'is_active' => true]);
        $v3 = $prod->variants()->create(['sku' => 'PARTIAL-V3', 'name' => 'Variant 3', 'stock_quantity' => 20, 'is_active' => true]);

        // Import payload updates ONLY v1 and v2; v3 is omitted!
        $updatedVariants = [
            ['id' => $v1->id, 'sku' => $v1->sku, 'name' => 'Updated Variant 1', 'stock_quantity' => 12],
            ['id' => $v2->id, 'sku' => $v2->sku, 'name' => 'Updated Variant 2', 'stock_quantity' => 18],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'ID' => (string)$prod->id,
                'SKU' => $prod->sku,
                'Name' => $prod->name,
                'Price' => '150.00',
                'Variant Summary' => json_encode($updatedVariants),
            ])
        ]);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv)])->json('import_token');
        // Partial import commit (full_replacement = false by default)
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        // Omitted variant V3 must still be active!
        $this->assertTrue($v3->fresh()->is_active);
        $this->assertEquals('Variant 3', $v3->fresh()->name);
        $this->assertEquals(20, $v3->fresh()->stock_quantity);

        $this->assertEquals('Updated Variant 1', $v1->fresh()->name);
        $this->assertEquals(12, $v1->fresh()->stock_quantity);
    }

    /**
     * 8. Ensuring explicit full-replacement imports deactivate omitted variants safely.
     */
    public function test_8_explicit_full_replacement_deactivates_omitted_variants_safely(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'sku' => 'REPLACE-SKU-01',
            'name' => 'Full Replacement Base',
            'slug' => 'full-replacement-base',
            'category_id' => $this->category->id,
            'price' => 200.00,
            'is_active' => true,
        ]);

        $vKeep = $prod->variants()->create(['sku' => 'REP-KEEP', 'name' => 'Keep Variant', 'stock_quantity' => 10, 'is_active' => true]);
        $vOmitted = $prod->variants()->create(['sku' => 'REP-OMIT', 'name' => 'Omitted Variant', 'stock_quantity' => 15, 'is_active' => true]);

        // CSV only contains vKeep; vOmitted is omitted, with full_replacement requested
        $variants = [
            ['id' => $vKeep->id, 'sku' => $vKeep->sku, 'name' => 'Updated Keep Variant', 'stock_quantity' => 14],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'ID' => (string)$prod->id,
                'SKU' => $prod->sku,
                'Name' => $prod->name,
                'Price' => '200.00',
                'Variant Summary' => json_encode($variants),
            ])
        ]);

        $valRes = $this->postJson('/api/admin/products/import/validate', [
            'file' => $this->createUploadedFile($csv),
            'full_replacement' => true,
        ]);
        $token = $valRes->json('import_token');

        // Commit with explicit full_replacement = true
        $commitRes = $this->postJson('/api/admin/products/import/commit', [
            'import_token' => $token,
            'full_replacement' => true,
        ]);
        $commitRes->assertOk();

        // Omitted variant must be deactivated safely, NOT deleted!
        $freshOmitted = $vOmitted->fresh();
        $this->assertNotNull($freshOmitted);
        $this->assertFalse($freshOmitted->is_active);

        // Kept variant must remain active
        $this->assertTrue($vKeep->fresh()->is_active);
    }

    /**
     * 9. Preserving legacy color/size imports.
     */
    public function test_9_preserving_legacy_color_size_imports(): void
    {
        $this->actAsAdmin();

        $sku = 'LEG-CAT-' . strtoupper(Str::random(6));
        $variants = [
            [
                'sku' => $sku . '-RED-XL',
                'name' => 'Red / XL',
                'color_name' => 'Crimson Red',
                'color_hex' => '#dc143c',
                'size' => 'XL',
                'price_modifier' => 12.00,
                'barcode' => '9988776655',
            ],
            [
                'sku' => $sku . '-BLU-S',
                'name' => 'Blue / S',
                'color_name' => 'Navy Blue',
                'color_hex' => '#000080',
                'size' => 'S',
                'price_modifier' => 0.00,
                'barcode' => '9988776656',
            ],
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => $sku,
                'Name' => 'Legacy Apparel Item',
                'Price' => '45.00',
                'Variant Summary' => json_encode($variants),
            ])
        ]);

        $file = $this->createUploadedFile($csv);
        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $file])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        $product = Product::where('sku', $sku)->with('variants')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->options); // No options declared
        $this->assertCount(2, $product->variants);

        $v1 = $product->variants()->where('sku', $sku . '-RED-XL')->first();
        $this->assertEquals('Crimson Red', $v1->color_name);
        $this->assertEquals('#dc143c', $v1->color_hex);
        $this->assertEquals('XL', $v1->size);
        $this->assertEquals(12.00, $v1->price_modifier);
        $this->assertEquals('9988776655', $v1->barcode);
        $this->assertNull($v1->attributes);
    }

    /**
     * 10. Exporting dynamic options and variant attributes.
     */
    public function test_10_exporting_dynamic_options_and_variant_attributes(): void
    {
        $this->actAsAdmin();

        $product = Product::create([
            'sku' => 'EXP-DYN-01',
            'name' => 'Export Dynamic Pro',
            'slug' => 'export-dynamic-pro',
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'brand' => $this->brand->name,
            'price' => 899.00,
            'is_active' => true,
            'options' => [
                ['name' => 'Color', 'values' => ['Midnight', 'Starlight']],
                ['name' => 'Memory', 'values' => ['16GB', '32GB']],
            ],
        ]);

        $v1 = $product->variants()->create([
            'sku' => 'EXP-DYN-01-MID-16',
            'name' => 'Midnight / 16GB',
            'price' => 899.00,
            'stock_quantity' => 12,
            'is_active' => true,
            'attributes' => ['Color' => 'Midnight', 'Memory' => '16GB'],
            'barcode' => '5012345678901',
        ]);

        $response = $this->get('/api/admin/products/export?search=EXP-DYN-01&include_options=1');
        $response->assertOk();

        $rows = $this->parseCsv($response->streamedContent());
        $this->assertCount(2, $rows);

        // Header includes Options column as 22nd header
        $headers = $rows[0];
        $this->assertCount(22, $headers);
        $this->assertEquals('Options', $headers[21]);

        $dataRow = $rows[1];
        // Variant summary check
        $variantJson = $dataRow[18];
        $decodedVariants = json_decode($variantJson, true);
        $this->assertIsArray($decodedVariants);
        $this->assertCount(1, $decodedVariants);
        $this->assertEquals('EXP-DYN-01-MID-16', $decodedVariants[0]['sku']);
        $this->assertEquals(899.00, $decodedVariants[0]['price']);
        $this->assertTrue($decodedVariants[0]['is_active']);
        $this->assertEquals(['Color' => 'Midnight', 'Memory' => '16GB'], $decodedVariants[0]['attributes']);

        // Options column check
        $optionsJson = $dataRow[21];
        $decodedOptions = json_decode($optionsJson, true);
        $this->assertCount(2, $decodedOptions);
        $this->assertEquals('Color', $decodedOptions[0]['name']);
        $this->assertEquals('Memory', $decodedOptions[1]['name']);
    }

    /**
     * 11. Exporting legacy products without changing their established format (exact 21 headers).
     */
    public function test_11_exporting_legacy_products_without_changing_established_format(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'sku' => 'EXP-LEGACY-01',
            'name' => 'Legacy Classic Export',
            'slug' => 'legacy-classic-export',
            'category_id' => $this->category->id,
            'price' => 39.99,
            'is_active' => true,
        ]);

        $prod->variants()->create([
            'sku' => 'EXP-LEG-S-BLK',
            'name' => 'Small Black',
            'color_name' => 'Black',
            'size' => 'S',
            'price_modifier' => 0.00,
            'stock_quantity' => 15,
        ]);

        $response = $this->get('/api/admin/products/export?search=EXP-LEGACY-01');
        $response->assertOk();

        $rows = $this->parseCsv($response->streamedContent());
        $this->assertCount(2, $rows);

        // Default export must maintain the exact 21 standard headers
        $expectedHeaders = $this->getStandardHeaders(false);
        $this->assertEquals($expectedHeaders, $rows[0]);

        $dataRow = $rows[1];
        $decodedVariants = json_decode($dataRow[18], true);
        $this->assertEquals('EXP-LEG-S-BLK', $decodedVariants[0]['sku']);
        $this->assertEquals('Black', $decodedVariants[0]['color_name']);
        $this->assertEquals('S', $decodedVariants[0]['size']);
    }

    /**
     * 12. Export/import round-trip behavior.
     */
    public function test_12_export_import_round_trip_behavior(): void
    {
        $this->actAsAdmin();

        $sku = 'ROUNDTRIP-' . strtoupper(Str::random(6));

        // Create product with dynamic variants
        $original = Product::create([
            'sku' => $sku,
            'name' => 'Roundtrip Dynamic Device',
            'slug' => strtolower($sku) . '-slug',
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'brand' => $this->brand->name,
            'price' => 499.00,
            'is_active' => true,
            'options' => [
                ['name' => 'Finish', 'values' => ['Matte', 'Glossy']],
            ],
        ]);

        $v1 = $original->variants()->create([
            'sku' => $sku . '-MATTE',
            'name' => 'Matte Finish',
            'price' => 499.00,
            'stock_quantity' => 18,
            'is_active' => true,
            'attributes' => ['Finish' => 'Matte'],
            'barcode' => '8881234567890',
        ]);

        $v2 = $original->variants()->create([
            'sku' => $sku . '-GLOSS',
            'name' => 'Glossy Finish',
            'price' => 529.00,
            'stock_quantity' => 7,
            'is_active' => true,
            'attributes' => ['Finish' => 'Glossy'],
            'barcode' => '8881234567891',
        ]);

        // Step 1: Export
        $exportRes = $this->get('/api/admin/products/export?search=' . $sku . '&include_options=1');
        $exportRes->assertOk();
        $exportedCsv = $exportRes->streamedContent();

        // Step 2: Validate the exported CSV directly
        $file = $this->createUploadedFile($exportedCsv, 'roundtrip.csv');
        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $file]);
        $valRes->assertOk()->assertJsonPath('can_commit', true);

        // Step 3: Commit the exported CSV
        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $valRes->json('import_token')]);
        $commitRes->assertOk()->assertJsonPath('status', 'success');

        // Step 4: Verify round-trip data preservation
        $reimported = Product::where('sku', $sku)->with('variants')->first();
        $this->assertEquals($original->id, $reimported->id);
        $this->assertEquals($original->options, $reimported->options);
        $this->assertCount(2, $reimported->variants);

        $reV1 = $reimported->variants()->where('sku', $sku . '-MATTE')->first();
        $this->assertEquals($v1->id, $reV1->id); // ID preserved
        $this->assertEquals(499.00, $reV1->price);
        $this->assertEquals(18, $reV1->stock_quantity);
        $this->assertEquals(['Finish' => 'Matte'], $reV1->attributes);
    }

    /**
     * 13. Preserving historical references and variant identities across re-imports.
     */
    public function test_13_preserving_historical_references_and_variant_identities(): void
    {
        $this->actAsAdmin();

        $prod = Product::create([
            'sku' => 'HIST-REF-PROD',
            'name' => 'Historical Reference Product',
            'slug' => 'hist-ref-prod',
            'category_id' => $this->category->id,
            'price' => 80.00,
            'is_active' => true,
        ]);

        $variant = $prod->variants()->create([
            'sku' => 'HIST-REF-VAR',
            'name' => 'Original Variant Name',
            'price' => 80.00,
            'stock_quantity' => 20,
            'attributes' => ['Type' => 'Standard'],
        ]);

        // Place an order referencing this variant
        $order = Order::create([
            'order_number' => 'ORD-HIST-' . strtoupper(Str::random(5)),
            'user_id' => $this->customer->id,
            'customer_name' => 'Customer Historian',
            'customer_email' => 'historian@example.com',
            'shipping_address' => '456 History Blvd',
            'subtotal' => 80.00,
            'total_amount' => 80.00,
            'order_status' => 'completed',
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $prod->id,
            'variant_id' => $variant->id,
            'product_name' => $prod->name,
            'variant_name' => $variant->name,
            'product_sku' => $variant->sku,
            'unit_price' => 80.00,
            'quantity' => 1,
            'total_price' => 80.00,
        ]);

        // Re-import updating the variant's price and name
        $updatedVariants = [
            [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => 'Renamed Historical Variant',
                'price' => 95.00,
                'stock_quantity' => 25,
                'attributes' => ['Type' => 'Standard'],
            ]
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'ID' => (string)$prod->id,
                'SKU' => $prod->sku,
                'Name' => $prod->name,
                'Price' => '80.00',
                'Variant Summary' => json_encode($updatedVariants),
            ])
        ]);

        $token = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv)])->json('import_token');
        $this->postJson('/api/admin/products/import/commit', ['import_token' => $token])->assertOk();

        // Foreign key in order_items must still point to the same variant ID
        $freshItem = $orderItem->fresh();
        $this->assertEquals($variant->id, $freshItem->variant_id);
        $this->assertEquals('Renamed Historical Variant', $freshItem->variant->name);
        $this->assertEquals(95.00, $freshItem->variant->price);
    }

    /**
     * 14. Transaction rollback and useful import error reporting.
     */
    public function test_14_transaction_rollback_and_useful_import_error_reporting(): void
    {
        $this->actAsAdmin();

        $initialProductCount = Product::count();
        $validSku = 'ROLLBACK-VALID-' . strtoupper(Str::random(4));
        $invalidSku = 'ROLLBACK-INVALID-' . strtoupper(Str::random(4));

        $validVariants = [
            ['sku' => $validSku . '-V1', 'name' => 'Valid V1', 'attributes' => ['Tier' => 'Gold']]
        ];

        // Second row has duplicate variant combination
        $invalidVariants = [
            ['sku' => $invalidSku . '-V1', 'name' => 'Dup 1', 'attributes' => ['Tier' => 'Silver']],
            ['sku' => $invalidSku . '-V2', 'name' => 'Dup 2', 'attributes' => ['Tier' => 'Silver']], // Duplicate combination!
        ];

        $csv = $this->buildCsvContent($this->getStandardHeaders(), [
            $this->buildRow([
                'SKU' => $validSku,
                'Name' => 'Valid Product In Batch',
                'Price' => '50.00',
                'Variant Summary' => json_encode($validVariants),
            ]),
            $this->buildRow([
                'SKU' => $invalidSku,
                'Name' => 'Invalid Product In Batch',
                'Price' => '60.00',
                'Variant Summary' => json_encode($invalidVariants),
            ]),
        ]);

        $valRes = $this->postJson('/api/admin/products/import/validate', ['file' => $this->createUploadedFile($csv)]);
        $valRes->assertOk()->assertJsonPath('can_commit', false);

        // Meaningful diagnostics pinpointing row number and duplicate combination
        $this->assertStringContainsString('Row 3: Duplicate variant combination', $valRes->json('rows.1.errors.0'));

        $token = $valRes->json('import_token');
        // Commit should fail with 422
        $commitRes = $this->postJson('/api/admin/products/import/commit', ['import_token' => $token]);
        $commitRes->assertStatus(422)->assertJsonFragment(['status' => 'error']);

        // Verify total rollback: zero records created
        $this->assertEquals($initialProductCount, Product::count());
        $this->assertNull(Product::where('sku', $validSku)->first());
    }
}
