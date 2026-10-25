<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 3);
        $unitPrice = fake()->randomFloat(2, 100, 1000);
        $total = $unitPrice * $qty;

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(2, true),
            'product_sku' => 'SKU-' . fake()->unique()->numerify('#####'),
            'unit_price' => $unitPrice,
            'cogs_unit_cost' => round($unitPrice * 0.6, 2),
            'cogs_total' => round($unitPrice * 0.6 * $qty, 2),
            'quantity' => $qty,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_price' => $total,
            'net_total' => $total,
            'gross_profit' => round($total - ($unitPrice * 0.6 * $qty), 2),
        ];
    }
}
