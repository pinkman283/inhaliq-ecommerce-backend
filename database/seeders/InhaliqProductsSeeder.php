<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InhaliqProductsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure core categories exist
        $categories = [
            'Disposable' => Category::firstOrCreate(['slug' => 'disposable'], ['name' => 'Disposable', 'is_featured' => true, 'display_order' => 1]),
            'Devices' => Category::firstOrCreate(['slug' => 'devices'], ['name' => 'Devices', 'is_featured' => true, 'display_order' => 2]),
            'NIC-Salt' => Category::firstOrCreate(['slug' => 'nic-salt'], ['name' => 'NIC-Salt', 'is_featured' => true, 'display_order' => 3]),
            'Pod' => Category::firstOrCreate(['slug' => 'pod'], ['name' => 'Pod', 'is_featured' => true, 'display_order' => 4]),
            'MOD' => Category::firstOrCreate(['slug' => 'mod'], ['name' => 'MOD', 'is_featured' => true, 'display_order' => 5]),
            'POD-MOD' => Category::firstOrCreate(['slug' => 'pod-mod'], ['name' => 'POD-MOD', 'is_featured' => true, 'display_order' => 6]),
        ];

        // 2. High-res vape product catalog matching Inhaliq production
        $items = [
            [
                'name' => 'Flyto Switch 6000 Puffs Prefilled Cartridge – Banana Coconut',
                'category' => 'Disposable',
                'sku' => 'P0001',
                'price' => 550.00,
                'stock' => 12,
                'image' => 'https://images.unsplash.com/photo-1527661591475-527312dd65f5?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Banana Coconut', 'Ice Mint'],
            ],
            [
                'name' => 'Flyto Switch 8000 Puffs Prefilled Cartridge – Mango Passion',
                'category' => 'Disposable',
                'sku' => 'P0002',
                'price' => 650.00,
                'stock' => 15,
                'image' => 'https://images.unsplash.com/photo-1546868871-7041f2a55e12?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Mango Passion', 'Peach Ice'],
            ],
            [
                'name' => 'Flyto Switch 6000 Puffs Prefilled Cartridge – Strawberry Kiwi',
                'category' => 'Disposable',
                'sku' => 'P0003',
                'price' => 550.00,
                'stock' => 10,
                'image' => 'https://images.unsplash.com/photo-1563245372-f21724e3856d?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Strawberry Kiwi'],
            ],
            [
                'name' => 'Flyto Switch 8000 Puffs Prefilled Cartridge – Watermelon Ice',
                'category' => 'Disposable',
                'sku' => 'P0004',
                'price' => 650.00,
                'stock' => 18,
                'image' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Watermelon Ice'],
            ],
            [
                'name' => 'Flyto Switch 6000 Puffs Prefilled Cartridge – Grape Freeze',
                'category' => 'Disposable',
                'sku' => 'P0005',
                'price' => 550.00,
                'stock' => 8,
                'image' => 'https://images.unsplash.com/photo-1527661591475-527312dd65f5?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Grape Freeze'],
            ],
            [
                'name' => 'Flyto Switch 6000 Puffs Prefilled Cartridge – Blue Razz',
                'category' => 'Disposable',
                'sku' => 'P0006',
                'price' => 550.00,
                'stock' => 20,
                'image' => 'https://images.unsplash.com/photo-1546868871-7041f2a55e12?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Blue Razz'],
            ],
            [
                'name' => 'D-Breath 12K Honeydew Melon Ice Disposable',
                'category' => 'Disposable',
                'sku' => 'D0001',
                'price' => 1300.00,
                'stock' => 25,
                'image' => 'https://images.unsplash.com/photo-1527661591475-527312dd65f5?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Honeydew Melon', 'Double Apple'],
            ],
            [
                'name' => 'Voltbar Switch 12000 Puffs Starter Kit',
                'category' => 'Devices',
                'sku' => 'V0001',
                'price' => 1650.00,
                'stock' => 14,
                'image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Matte Black', 'Gradient Blue'],
            ],
            [
                'name' => 'Rincoe Jellybox XS 30W Pod System Device',
                'category' => 'Pod',
                'sku' => 'R0001',
                'price' => 2450.00,
                'stock' => 9,
                'image' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Clear Avocado', 'Full Black'],
            ],
            [
                'name' => 'Oxva Xlim Pro 30W Pod Kit Carbon Edition',
                'category' => 'Pod',
                'sku' => 'O0001',
                'price' => 2850.00,
                'stock' => 16,
                'image' => 'https://images.unsplash.com/photo-1546868871-7041f2a55e12?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Black Carbon', 'Grey Carbon'],
            ],
            [
                'name' => 'Tokyo Crazy Fruits Salt Nic 30ml – Green Apple',
                'category' => 'NIC-Salt',
                'sku' => 'T0001',
                'price' => 1250.00,
                'stock' => 30,
                'image' => 'https://images.unsplash.com/photo-1563245372-f21724e3856d?auto=format&fit=crop&w=400&q=80',
                'variants' => ['35mg', '50mg'],
            ],
            [
                'name' => 'Nasty Juice Cush Man Mango Salt Nic 30ml',
                'category' => 'NIC-Salt',
                'sku' => 'N0001',
                'price' => 1400.00,
                'stock' => 22,
                'image' => 'https://images.unsplash.com/photo-1527661591475-527312dd65f5?auto=format&fit=crop&w=400&q=80',
                'variants' => ['35mg', '50mg'],
            ],
            [
                'name' => 'Geekvape Aegis Legend 2 L200 Box Mod 200W',
                'category' => 'MOD',
                'sku' => 'G0001',
                'price' => 4800.00,
                'stock' => 6,
                'image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Classic Black', 'Navy Blue'],
            ],
            [
                'name' => 'Voopoo Drag 4 177W Pod Mod Kit',
                'category' => 'POD-MOD',
                'sku' => 'V0004',
                'price' => 4500.00,
                'stock' => 7,
                'image' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=400&q=80',
                'variants' => ['Gunmetal & Rosewood', 'Black & Walnut'],
            ],
        ];

        foreach ($items as $data) {
            $cat = $categories[$data['category']] ?? $categories['Disposable'];
            
            $slug = Str::slug($data['name']);
            // Ensure unique slug
            $existing = Product::where('slug', $slug)->first();
            if ($existing) {
                $slug .= '-' . Str::random(4);
            }

            $product = Product::create([
                'category_id' => $cat->id,
                'name' => $data['name'],
                'slug' => $slug,
                'sku' => $data['sku'],
                'short_description' => $data['name'] . ' premium genuine stock.',
                'description' => 'Original factory sealed stock imported directly with official warranty.',
                'price' => $data['price'],
                'compare_at_price' => round($data['price'] * 1.15, 2),
                'stock_quantity' => $data['stock'],
                'is_active' => true,
                'is_featured' => true,
                'is_new_arrival' => true,
                'is_best_seller' => true,
                'rating_average' => 4.9,
                'review_count' => rand(12, 85),
            ]);

            // Add primary image
            ProductImage::create([
                'product_id' => $product->id,
                'image_url' => $data['image'],
                'is_primary' => true,
                'display_order' => 1,
            ]);

            // Add variants if any
            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $vName) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'name' => $vName,
                        'sku' => $data['sku'] . '-' . Str::upper(Str::slug($vName)),
                        'barcode' => '880' . rand(10000000, 99999999),
                        'price_modifier' => 0.00,
                        'stock_quantity' => round($data['stock'] / count($data['variants'])),
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
}
