<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code'             => strtoupper($this->faker->bothify('??##??')),
            'kind'             => $this->faker->randomElement(['percent', 'fixed']),
            'amount'           => $this->faker->numberBetween(5, 50),
            'duration'         => $this->faker->randomElement(['once', 'forever', 'repeating']),
            'duration_in_months' => null,
            'applies_to_plan_ids' => null,
            'max_redemptions'  => $this->faker->optional()->numberBetween(10, 1000),
            'times_redeemed'   => 0,
            'enabled'          => true,
            'expires_at'       => null,
            'stripe_coupon_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
