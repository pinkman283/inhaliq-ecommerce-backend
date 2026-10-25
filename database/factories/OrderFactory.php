<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 200, 3000);
        $shipping = 100.00;
        $total = $subtotal + $shipping;

        return [
            'order_number' => 'ORD-' . strtoupper(Str::random(10)),
            'order_source' => 'online',
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '01' . fake()->numerify('#########'),
            'shipping_address' => [
                'address_line1' => fake()->streetAddress(),
                'city' => 'Dhaka',
                'postal_code' => '1200',
                'country' => 'Bangladesh',
            ],
            'subtotal' => $subtotal,
            'tax_amount' => 0.00,
            'shipping_amount' => $shipping,
            'discount_amount' => 0.00,
            'total_amount' => $total,
            'payment_status' => 'pending',
            'payment_method' => 'cod',
            'order_status' => 'pending',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
            'order_status' => 'processing',
        ]);
    }
}
