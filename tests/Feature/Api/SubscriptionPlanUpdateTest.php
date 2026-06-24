<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Country;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SubscriptionPlanUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscription-plans-edit', 'guard_name' => 'web']);
        $role->givePermissionTo('subscription-plans-edit');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->country = Country::create([
            'name_en' => 'Egypt',
            'name_ar' => 'مصر',
            'iso_code' => 'EG',
            'currency_name' => 'Egyptian Pound',
            'currency_code' => 'EGP',
            'status' => true,
        ]);
    }

    public function test_update_preserves_slug_when_name_unchanged_despite_duplicate_name_elsewhere(): void
    {
        SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => false,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-legacy',
            'price' => 120,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $token = $this->admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/subscription-plans/{$plan->id}", [
            'name' => 'Premium',
            'description' => 'Updated description',
            'billing_cycle' => 'monthly',
            'price' => 130,
            'countries' => [
                [
                    'country_id' => $this->country->id,
                    'price' => 130,
                ],
            ],
        ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertSame('premium-legacy', $plan->slug);
        $this->assertSame('Updated description', $plan->description);
    }

    public function test_update_regenerates_unique_slug_when_name_changes(): void
    {
        SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => false,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Legacy Plan',
            'slug' => 'legacy-plan',
            'price' => 120,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $token = $this->admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/subscription-plans/{$plan->id}", [
            'name' => 'Premium',
            'billing_cycle' => 'monthly',
            'price' => 120,
            'countries' => [
                [
                    'country_id' => $this->country->id,
                    'price' => 120,
                ],
            ],
        ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertSame('premium-1', $plan->slug);
    }

    public function test_unique_slug_for_name_excludes_current_plan_and_soft_deleted_rows(): void
    {
        $service = app(SubscriptionPlanService::class);

        SubscriptionPlan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $deleted = SubscriptionPlan::create([
            'name' => 'Basic Plus',
            'slug' => 'basic-1',
            'price' => 60,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => false,
        ]);
        $deleted->delete();

        $plan = SubscriptionPlan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 40,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $this->assertSame('basic-2', $service->uniqueSlugForName('Basic', $plan->id));
    }
}
