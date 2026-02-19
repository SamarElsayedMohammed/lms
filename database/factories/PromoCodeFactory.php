<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCode>
 */
final class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    #[\Override]
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'promo_code' => strtoupper(fake()->unique()->lexify('PROMO????')),
            'message' => fake()->sentence(),
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'no_of_users' => 100,
            'discount' => 10,
            'discount_type' => 'percentage',
            'repeat_usage' => false,
            'no_of_repeat_usage' => 1,
            'status' => 1,
        ];
    }

    /**
     * Percentage discount promo code
     */
    public function percentage(float $percent): static
    {
        return $this->state(fn() => [
            'discount_type' => 'percentage',
            'discount' => $percent,
        ]);
    }

    /**
     * Fixed amount discount promo code
     */
    public function fixedAmount(float $amount): static
    {
        return $this->state(fn() => [
            'discount_type' => 'amount',
            'discount' => $amount,
        ]);
    }

    /**
     * Expired promo code
     */
    public function expired(): static
    {
        return $this->state(fn() => [
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(),
        ]);
    }

    /**
     * Inactive promo code
     */
    public function inactive(): static
    {
        return $this->state(fn() => [
            'status' => 0,
        ]);
    }

    /**
     * Promo code with usage limit reached
     */
    public function exhausted(): static
    {
        return $this->state(fn() => [
            'no_of_users' => 0,
        ]);
    }
}
