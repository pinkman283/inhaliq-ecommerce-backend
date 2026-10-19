<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

$envFile = __DIR__ . '/../frontend/.env.local';
$baseUrl = 'https://www.inhaliq.com/api/v1';
$apiKey = '1AX3OHg9.:0v';

if (file_exists($envFile)) {
    $content = file_get_contents($envFile);
    if (preg_match('/SENIOR_API_BASE_URL=["\']([^"\']+)["\']/', $content, $m)) {
        $baseUrl = $m[1];
    }
    if (preg_match('/SENIOR_API_KEY=["\']([^"\']+)["\']/', $content, $m)) {
        $apiKey = $m[1];
    }
}

echo "Fetching Senior products from {$baseUrl}/shop...\n";

$ch = curl_init("{$baseUrl}/shop?per_page=100");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    "X-API-KEY: {$apiKey}",
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo "Failed to fetch from Senior API. Code: {$httpCode}\n";
    exit(1);
}

$data = json_decode($response, true);
$rawProducts = $data['data']['products']['data'] ?? $data['data']['products'] ?? [];

echo "Found " . count($rawProducts) . " products from Senior Backend.\n";

// Delete previous mock dummy products
Product::whereBetween('id', [1290, 1320])->delete();

// Map or create default category
$defaultCategory = Category::firstOrCreate(['slug' => 'disposable'], [
    'name' => 'Disposable',
    'is_featured' => true,
    'display_order' => 1,
]);

$syncedCount = 0;

foreach ($rawProducts as $rp) {
    $name = trim($rp['name'] ?? 'Product');
    $price = (float) ($rp['new_price'] ?? $rp['price'] ?? 550);
    $stock = (int) ($rp['stock'] ?? 10);
    $sku = 'INH-' . str_pad($rp['id'], 4, '0', STR_PAD_LEFT);
    $imageUrl = $rp['image'] ?? null;
    $slug = Str::slug($name) . '-' . $rp['id'];

    // Determine category based on product name
    $lowerName = strtolower($name);
    $categoryName = 'Disposable';
    if (str_contains($lowerName, 'kit') || str_contains($lowerName, 'mod') || str_contains($lowerName, 'device')) {
        $categoryName = 'Devices';
    } elseif (str_contains($lowerName, 'salt') || str_contains($lowerName, 'nic')) {
        $categoryName = 'NIC-Salt';
    } elseif (str_contains($lowerName, 'cartridge') || str_contains($lowerName, 'pod')) {
        $categoryName = 'Pod';
    }

    $category = Category::firstOrCreate(['slug' => Str::slug($categoryName)], [
        'name' => $categoryName,
        'is_featured' => true,
    ]);

    $product = Product::where('slug', $slug)->first() ?? Product::where('name', $name)->first() ?? new Product();

    $sku = 'INH-' . str_pad($rp['id'], 4, '0', STR_PAD_LEFT);
    if (Product::where('sku', $sku)->where('id', '!=', $product->id ?? 0)->exists()) {
        $sku = 'INH-' . $rp['id'] . '-' . Str::lower(Str::random(3));
    }

    $product->name = $name;
    $product->slug = $slug;
    $product->sku = $sku;
    $product->category_id = $category->id;
    $product->price = $price;
    $product->compare_at_price = !empty($rp['old_price']) ? (float) $rp['old_price'] : null;
    $product->stock_quantity = $stock;
    $product->is_active = true;
    $product->is_featured = true;
    $product->description = $rp['description'] ?? "Official {$name} from Inhaliq.";
    $product->save();

    // Update product image
    if ($imageUrl) {
        // Remove existing primary image
        ProductImage::where('product_id', $product->id)->delete();
        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => $imageUrl,
            'is_primary' => true,
            'display_order' => 1,
        ]);
    }

    // Add variants if present in Senior API or synthesize flavour variant
    if (!empty($rp['variants']) && is_array($rp['variants'])) {
        foreach ($rp['variants'] as $v) {
            ProductVariant::updateOrCreate(
                ['product_id' => $product->id, 'name' => $v['name'] ?? 'Default'],
                [
                    'sku' => $sku . '-' . Str::slug($v['name'] ?? 'VAR'),
                    'price' => (float) ($v['price'] ?? $price),
                    'price_modifier' => 0,
                    'stock_quantity' => (int) ($v['stock'] ?? $stock),
                    'is_active' => true,
                ]
            );
        }
    } else {
        // Extract flavor variant from name if separated by – or -
        $parts = preg_split('/[–\-]/u', $name);
        if (count($parts) > 1) {
            $flavor = trim(end($parts));
            ProductVariant::updateOrCreate(
                ['product_id' => $product->id, 'name' => $flavor],
                [
                    'sku' => $sku . '-' . Str::slug($flavor),
                    'price' => $price,
                    'price_modifier' => 0,
                    'stock_quantity' => $stock,
                    'is_active' => true,
                ]
            );
        }
    }

    $syncedCount++;
}

echo "Successfully synced {$syncedCount} real products and images from Senior Backend!\n";
