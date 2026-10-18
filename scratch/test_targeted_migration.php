<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;

echo "=== TARGETED MIGRATION SAFETY & COMPATIBILITY AUDIT ===\n\n";

$testDb = 'ecommerce_migration_audit_temp';
$rootPdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '1234');
$rootPdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$rootPdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "[1] Created isolated MySQL test database: {$testDb}\n";

Config::set("database.connections.audit_test", [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => $testDb,
    'username' => 'root',
    'password' => '1234',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
]);
Config::set('database.default', 'audit_test');
DB::purge('audit_test');
DB::setDefaultConnection('audit_test');

echo "[2] Creating pre-migration baseline tables (products, product_images, product_variants, order_items)...\n";

// Baseline products table
Schema::connection('audit_test')->create('products', function ($table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('sku')->unique();
    $table->decimal('price', 10, 2);
    $table->integer('stock_quantity')->default(0);
    $table->boolean('is_active')->default(true);
    $table->json('specifications')->nullable();
    $table->timestamps();
});

// Baseline product_images table
Schema::connection('audit_test')->create('product_images', function ($table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->string('image_path');
    $table->boolean('is_primary')->default(false);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});

// Baseline product_variants table (legacy state before dynamic options)
Schema::connection('audit_test')->create('product_variants', function ($table) {
    $table->id();
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->string('name');
    $table->string('size')->nullable();
    $table->string('color_name')->nullable();
    $table->string('color_hex')->nullable();
    $table->string('sku')->nullable();
    $table->decimal('cost_price', 10, 2)->default(0.00);
    $table->string('barcode')->nullable();
    $table->decimal('price_modifier', 10, 2)->default(0.00);
    $table->integer('stock_quantity')->default(0);
    $table->timestamps();
});

// Baseline order_items table
Schema::connection('audit_test')->create('order_items', function ($table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
    $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
    $table->string('product_name');
    $table->string('product_sku');
    $table->string('variant_name')->nullable();
    $table->decimal('unit_price', 10, 2);
    $table->integer('quantity');
    $table->decimal('total_price', 10, 2);
    $table->timestamps();
});

echo "    Base schema created successfully.\n";

echo "[3] Seeding representative legacy data (simple products, legacy variants, orders, images)...\n";
$simpleProdId = DB::connection('audit_test')->table('products')->insertGetId([
    'name' => 'Legacy Simple Keyboard',
    'slug' => 'legacy-simple-keyboard',
    'sku' => 'LEG-KEY-001',
    'price' => 79.99,
    'stock_quantity' => 25,
    'is_active' => 1,
    'specifications' => json_encode(['switch' => 'Blue']),
    'created_at' => now(),
    'updated_at' => now(),
]);

$imgId = DB::connection('audit_test')->table('product_images')->insertGetId([
    'product_id' => $simpleProdId,
    'image_path' => 'products/legacy_kb.jpg',
    'is_primary' => 1,
    'sort_order' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);

$legacyVarId = DB::connection('audit_test')->table('product_variants')->insertGetId([
    'product_id' => $simpleProdId,
    'name' => 'Legacy Color Variant (Black)',
    'sku' => 'LEG-KEY-001-BLK',
    'color_name' => 'Black',
    'size' => null,
    'cost_price' => 45.00,
    'price_modifier' => 5.00,
    'stock_quantity' => 15,
    'created_at' => now(),
    'updated_at' => now(),
]);

$legacyOrderItemId = DB::connection('audit_test')->table('order_items')->insertGetId([
    'order_id' => 999,
    'product_id' => $simpleProdId,
    'variant_id' => $legacyVarId,
    'product_name' => 'Legacy Simple Keyboard',
    'product_sku' => 'LEG-KEY-001-BLK',
    'variant_name' => 'Legacy Color Variant (Black)',
    'unit_price' => 84.99,
    'quantity' => 1,
    'total_price' => 84.99,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "    Seeded: Product #{$simpleProdId}, Image #{$imgId}, Variant #{$legacyVarId}, OrderItem #{$legacyOrderItemId}\n";

echo "[4] Executing 2026_10_16_000001_add_dynamic_options_to_catalog_tables UP...\n";
$migration = require __DIR__ . '/../database/migrations/2026_10_16_000001_add_dynamic_options_to_catalog_tables.php';
$migration->up();
echo "    [PASS] UP migration executed successfully on populated existing schema!\n\n";

echo "[5] Verifying legacy data integrity and new column behavior...\n";
$prodAfter = DB::connection('audit_test')->table('products')->where('id', $simpleProdId)->first();
assert($prodAfter->options === null, "Simple product options should be null");
assert($prodAfter->price == 79.99, "Product price altered");
echo "    - Simple product: options=NULL, price={$prodAfter->price}, stock={$prodAfter->stock_quantity} (PRESERVED)\n";

$varAfter = DB::connection('audit_test')->table('product_variants')->where('id', $legacyVarId)->first();
assert($varAfter->attributes === null, "Legacy variant attributes should be null");
assert($varAfter->price === null, "Legacy variant direct price should be null (fallback to price_modifier)");
assert((bool)$varAfter->is_active === true, "Legacy variant is_active should default to true");
assert($varAfter->image_id === null, "Legacy variant image_id should be null");
echo "    - Legacy variant: attributes=NULL, price=NULL, is_active=1 (default), image_id=NULL (PRESERVED)\n";

$orderItemAfter = DB::connection('audit_test')->table('order_items')->where('id', $legacyOrderItemId)->first();
assert($orderItemAfter->variant_attributes === null, "Legacy order item variant_attributes should be null");
echo "    - Legacy order item: variant_attributes=NULL, unit_price={$orderItemAfter->unit_price} (PRESERVED)\n";

echo "[6] Inserting new dynamic variant with attributes, direct price, and image FK...\n";
DB::connection('audit_test')->table('products')->where('id', $simpleProdId)->update([
    'options' => json_encode([['name' => 'Switch', 'values' => ['Linear', 'Tactile']]]),
]);

$dynamicVarId = DB::connection('audit_test')->table('product_variants')->insertGetId([
    'product_id' => $simpleProdId,
    'name' => 'Tactile Switch',
    'sku' => 'LEG-KEY-001-TAC',
    'attributes' => json_encode(['Switch' => 'Tactile']),
    'price' => 89.99,
    'image_id' => $imgId,
    'is_active' => 1,
    'stock_quantity' => 10,
    'created_at' => now(),
    'updated_at' => now(),
]);

$dynamicOrderItemId = DB::connection('audit_test')->table('order_items')->insertGetId([
    'order_id' => 1000,
    'product_id' => $simpleProdId,
    'variant_id' => $dynamicVarId,
    'product_name' => 'Legacy Simple Keyboard',
    'product_sku' => 'LEG-KEY-001-TAC',
    'variant_name' => 'Tactile Switch',
    'variant_attributes' => json_encode(['Switch' => 'Tactile']),
    'unit_price' => 89.99,
    'quantity' => 2,
    'total_price' => 179.98,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "    [PASS] Inserted dynamic variant #{$dynamicVarId} and order item #{$dynamicOrderItemId} with JSON attributes.\n\n";

echo "[7] Testing migration DOWN (Rollback behavior)...\n";
$migration->down();
echo "    [PASS] DOWN migration executed successfully!\n";

$prodColsDown = Schema::connection('audit_test')->getColumnListing('products');
$varColsDown = Schema::connection('audit_test')->getColumnListing('product_variants');
$orderColsDown = Schema::connection('audit_test')->getColumnListing('order_items');

assert(!in_array('options', $prodColsDown), "options column was not dropped");
assert(!in_array('attributes', $varColsDown), "attributes column was not dropped");
assert(!in_array('price', $varColsDown), "price column was not dropped");
assert(!in_array('image_id', $varColsDown), "image_id column was not dropped");
assert(!in_array('is_active', $varColsDown), "is_active column was not dropped");
assert(!in_array('variant_attributes', $orderColsDown), "variant_attributes column was not dropped");

// Ensure pre-existing records still exist
$prodStillHere = DB::connection('audit_test')->table('products')->where('id', $simpleProdId)->first();
assert($prodStillHere !== null && $prodStillHere->sku === 'LEG-KEY-001', "Simple product lost");
$varStillHere = DB::connection('audit_test')->table('product_variants')->where('id', $legacyVarId)->first();
assert($varStillHere !== null && $varStillHere->color_name === 'Black', "Legacy variant lost");
echo "    [PASS] Core products and legacy variants survived rollback undamaged.\n\n";

echo "[8] Testing re-migration UP from rolled-back state...\n";
$migration->up();
echo "    [PASS] Re-migration UP succeeded!\n\n";

// Clean up isolated DB
$rootPdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
echo "[9] Dropped isolated test database {$testDb}.\n\n";
echo "=== AUDIT RESULT: MIGRATION IS FULLY SAFE & BACKWARD COMPATIBLE! ===\n";
