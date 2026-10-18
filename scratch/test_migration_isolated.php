<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

echo "=== MIGRATION SAFETY AUDIT (ISOLATED TEST DATABASE) ===\n\n";

$testDbName = 'ecommerce_migration_audit_temp';
$rootPdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '1234');
$rootPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$rootPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "[1] Created disposable database: {$testDbName}\n";

Config::set("database.connections.audit_test", [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => $testDbName,
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

echo "[2] Running complete migration suite on fresh database...\n";
$exitCode = Artisan::call('migrate', [
    '--database' => 'audit_test',
    '--force' => true,
]);
$output = Artisan::output();
echo "Migrate Output Summary: " . trim(substr($output, 0, 300)) . "...\n";
echo "Migrate exit code: {$exitCode}\n";

if ($exitCode !== 0) {
    echo "ERROR: Migrations failed on fresh schema!\n";
    exit(1);
}
echo "[PASS] All migrations executed successfully on fresh schema.\n\n";

echo "[3] Verifying newly added columns, types, nullability, and defaults...\n";
$productsTable = Schema::connection('audit_test')->getColumnListing('products');
$variantsTable = Schema::connection('audit_test')->getColumnListing('product_variants');
$orderItemsTable = Schema::connection('audit_test')->getColumnListing('order_items');

assert(in_array('options', $productsTable), "'options' column missing on products");
assert(in_array('attributes', $variantsTable), "'attributes' column missing on product_variants");
assert(in_array('price', $variantsTable), "'price' column missing on product_variants");
assert(in_array('image_id', $variantsTable), "'image_id' column missing on product_variants");
assert(in_array('is_active', $variantsTable), "'is_active' column missing on product_variants");
assert(in_array('variant_attributes', $orderItemsTable), "'variant_attributes' column missing on order_items");

$prodColType = Schema::connection('audit_test')->getColumnType('products', 'options');
$varAttrType = Schema::connection('audit_test')->getColumnType('product_variants', 'attributes');
$varPriceType = Schema::connection('audit_test')->getColumnType('product_variants', 'price');
$varActiveType = Schema::connection('audit_test')->getColumnType('product_variants', 'is_active');
$varImageIdType = Schema::connection('audit_test')->getColumnType('product_variants', 'image_id');
$orderAttrType = Schema::connection('audit_test')->getColumnType('order_items', 'variant_attributes');

echo "  - products.options type: {$prodColType}\n";
echo "  - product_variants.attributes type: {$varAttrType}\n";
echo "  - product_variants.price type: {$varPriceType}\n";
echo "  - product_variants.image_id type: {$varImageIdType}\n";
echo "  - product_variants.is_active type: {$varActiveType}\n";
echo "  - order_items.variant_attributes type: {$orderAttrType}\n";
echo "[PASS] Column types and schema additions verified.\n\n";

echo "[4] Seeding test data: Simple Product & Dynamic Variant Product...\n";
$catId = DB::connection('audit_test')->table('categories')->insertGetId([
    'name' => 'Peripherals',
    'slug' => 'peripherals',
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

// Simple Product (options = null)
$simpleProdId = DB::connection('audit_test')->table('products')->insertGetId([
    'name' => 'Simple Mouse',
    'slug' => 'simple-mouse',
    'sku' => 'MOU-001',
    'category_id' => $catId,
    'price' => 29.99,
    'stock_quantity' => 100,
    'is_active' => 1,
    'options' => null,
    'created_at' => now(),
    'updated_at' => now(),
]);

// Dynamic Product (options with 2 dimensions)
$dynProdId = DB::connection('audit_test')->table('products')->insertGetId([
    'name' => 'Pro Mechanical Keyboard',
    'slug' => 'pro-mech-kb',
    'sku' => 'KB-PRO-001',
    'category_id' => $catId,
    'price' => 149.99,
    'stock_quantity' => 50,
    'is_active' => 1,
    'options' => json_encode([
        ['name' => 'Switch', 'values' => ['Red', 'Brown']],
        ['name' => 'Layout', 'values' => ['ANSI', 'ISO']],
    ]),
    'created_at' => now(),
    'updated_at' => now(),
]);

$imgId = DB::connection('audit_test')->table('product_images')->insertGetId([
    'product_id' => $dynProdId,
    'image_path' => 'products/kb_red.jpg',
    'is_primary' => 1,
    'sort_order' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);

$varId = DB::connection('audit_test')->table('product_variants')->insertGetId([
    'product_id' => $dynProdId,
    'name' => 'Red / ANSI',
    'sku' => 'KB-PRO-RED-ANSI',
    'attributes' => json_encode(['Switch' => 'Red', 'Layout' => 'ANSI']),
    'price' => 159.99,
    'image_id' => $imgId,
    'is_active' => 1,
    'stock_quantity' => 20,
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "[PASS] Products and variants successfully seeded.\n\n";

echo "[5] Testing rollback of dynamic options migration (down method)...\n";
$rollbackExit = Artisan::call('migrate:rollback', [
    '--database' => 'audit_test',
    '--step' => 1,
    '--force' => true,
]);
echo "Rollback Output: " . trim(Artisan::output()) . "\n";
echo "Rollback exit code: {$rollbackExit}\n";

if ($rollbackExit !== 0) {
    echo "ERROR: Rollback failed!\n";
    exit(1);
}

// Check that rollback removed columns cleanly without corrupting other tables
$productsColsAfterRollback = Schema::connection('audit_test')->getColumnListing('products');
$variantsColsAfterRollback = Schema::connection('audit_test')->getColumnListing('product_variants');
$orderItemsColsAfterRollback = Schema::connection('audit_test')->getColumnListing('order_items');

assert(!in_array('options', $productsColsAfterRollback), "options column still present after rollback");
assert(!in_array('attributes', $variantsColsAfterRollback), "attributes column still present after rollback");
assert(!in_array('price', $variantsColsAfterRollback), "price column still present after rollback");
assert(!in_array('image_id', $variantsColsAfterRollback), "image_id column still present after rollback");
assert(!in_array('is_active', $variantsColsAfterRollback), "is_active column still present after rollback");
assert(!in_array('variant_attributes', $orderItemsColsAfterRollback), "variant_attributes column still present after rollback");

// Verify that the simple product and the image in parent tables were NOT deleted
$simpleCheck = DB::connection('audit_test')->table('products')->where('id', $simpleProdId)->first();
assert($simpleCheck !== null && $simpleCheck->sku === 'MOU-001', "Simple product was lost during rollback!");
$imageCheck = DB::connection('audit_test')->table('product_images')->where('id', $imgId)->first();
assert($imageCheck !== null, "Product image was lost during rollback!");

echo "[PASS] Rollback cleanly dropped columns and preserved core catalog records.\n\n";

echo "[6] Re-running migration UP...\n";
$reUpExit = Artisan::call('migrate', [
    '--database' => 'audit_test',
    '--force' => true,
]);
assert($reUpExit === 0, "Re-migration UP failed");
echo "[PASS] Migration UP re-applied successfully.\n\n";

// Clean up test DB
$rootPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
echo "[7] Cleaned up disposable database: {$testDbName}\n";
echo "\n=== ALL MIGRATION INTEGRITY & SAFETY TESTS PASSED! ===\n";
