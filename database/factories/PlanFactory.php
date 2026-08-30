<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);
        return [
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)) . '-' . fake()->unique()->numberBetween(1, 9999),
            'price_cents' => 999,
            'currency_code' => 'USD',
            'interval' => 'month',
            'sort_order' => 0,
            'enabled' => true,
        ];
    }
}
