<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
final class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    #[\Override]
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'description' => fake()->paragraph(),
            'duration_days' => 30,
            'billing_cycle' => 'monthly',
            'price' => fake()->randomFloat(2, 50, 500),
            'usd_price' => fake()->randomFloat(2, 5, 50),
            'commission_type' => 'fixed',
            'commission_rate' => 0.00,
            'features' => ['Feature 1', 'Feature 2'],
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}
