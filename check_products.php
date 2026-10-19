<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Total products: " . \App\Models\Product::count() . "\n";
$products = \App\Models\Product::with(['primaryImage'])->orderBy('id', 'asc')->take(10)->get();
foreach ($products as $p) {
    echo "ID: " . $p->id . "\n";
    echo "Name: " . $p->name . "\n";
    echo "SKU: " . $p->sku . "\n";
    echo "Primary Image URL: " . ($p->primaryImage?->image_url ?? 'none') . "\n";
    echo "Images count: " . $p->images->count() . "\n";
    foreach ($p->images as $img) {
        echo "  - " . $img->image_url . "\n";
    }
    echo "--------------------------\n";
}
