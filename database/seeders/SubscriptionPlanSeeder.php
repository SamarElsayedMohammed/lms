<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

final class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'شهري',
                'slug' => 'monthly',
                'description' => 'اشتراك شهري - وصول كامل لجميع الدورات',
                'billing_cycle' => 'monthly',
                'duration_days' => 30,
                'price' => 100,
                'commission_rate' => 10,
                'features' => ['وصول لجميع الدورات', 'دعم فني', 'شهادة إتمام'],
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'ربع سنوي',
                'slug' => 'quarterly',
                'description' => 'اشتراك ربع سنوي - وفر 10%',
                'billing_cycle' => 'quarterly',
                'duration_days' => 90,
                'price' => 270,
                'commission_rate' => 12,
                'features' => ['وصول لجميع الدورات', 'دعم فني', 'شهادة إتمام', 'توفير 10%'],
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'نصف سنوي',
                'slug' => 'semi-annual',
                'description' => 'اشتراك نصف سنوي - وفر 17%',
                'billing_cycle' => 'semi_annual',
                'duration_days' => 180,
                'price' => 500,
                'commission_rate' => 15,
                'features' => ['وصول لجميع الدورات', 'دعم فني', 'شهادة إتمام', 'توفير 17%'],
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'سنوي',
                'slug' => 'yearly',
                'description' => 'اشتراك سنوي - وفر 25%',
                'billing_cycle' => 'yearly',
                'duration_days' => 365,
                'price' => 900,
                'commission_rate' => 20,
                'features' => ['وصول لجميع الدورات', 'دعم فني', 'شهادة إتمام', 'توفير 25%'],
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'مدى الحياة',
                'slug' => 'lifetime',
                'description' => 'اشتراك مدى الحياة - وصول دائم',
                'billing_cycle' => 'lifetime',
                'duration_days' => null,
                'price' => 2500,
                'commission_rate' => 25,
                'features' => ['وصول دائم لجميع الدورات', 'دعم فني', 'شهادة إتمام', 'بدون تجديد'],
                'sort_order' => 5,
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
