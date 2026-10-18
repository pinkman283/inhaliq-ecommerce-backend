<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== AUDIT TEST DATA IN DEVELOPMENT DATABASE ===\n\n";

$testProducts = DB::table('products')
    ->where('name', 'LIKE', '%Aether Apex%')
    ->orWhere('sku', 'LIKE', '%AAPEX%')
    ->get();

echo "1. Test Products (" . $testProducts->count() . " found):\n";
$prodIds = [];
foreach ($testProducts as $p) {
    $prodIds[] = $p->id;
    echo "  - Product ID: {$p->id} | Name: {$p->name} | SKU: {$p->sku} | Created: {$p->created_at}\n";
}

$testVariants = [];
if (!empty($prodIds)) {
    $testVariants = DB::table('product_variants')
        ->whereIn('product_id', $prodIds)
        ->get();
}
echo "\n2. Associated Test Variants (" . count($testVariants) . " found):\n";
$varIds = [];
foreach ($testVariants as $v) {
    $varIds[] = $v->id;
    echo "  - Variant ID: {$v->id} | Product ID: {$v->product_id} | Name: {$v->name} | SKU: {$v->sku} | Stock: {$v->stock_quantity}\n";
}

$testOrders = DB::table('orders')
    ->where('order_number', 'LIKE', 'ORD-2026-%')
    ->orWhere('customer_email', 'LIKE', '%test%')
    ->orWhere('customer_email', 'LIKE', '%aether%')
    ->get();

echo "\n3. Candidate Test Orders (" . $testOrders->count() . " found):\n";
foreach ($testOrders as $o) {
    echo "  - Order ID: {$o->id} | Order Number: {$o->order_number} | Customer: {$o->customer_name} ({$o->customer_email}) | Total: {$o->total_amount} | Date: {$o->created_at}\n";
}
