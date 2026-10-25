<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Small / Red', 'Medium / Blue', 'Large / Black']),
            'sku' => 'VAR-' . strtoupper(Str::random(8)),
            'price_modifier' => fake()->randomFloat(2, 0, 100),
            'cost_price' => fake()->randomFloat(2, 30, 100),
            'stock_quantity' => fake()->numberBetween(5, 50),
            'is_active' => true,
        ];
    }
}
