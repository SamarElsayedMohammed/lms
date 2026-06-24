<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Country;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
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

    /** @var array<int, Country> */
    private array $countries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'subscription-plans-edit', 'guard_name' => 'web']);
        $role->givePermissionTo('subscription-plans-edit');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->countries = [
            1 => Country::create([
                'name_en' => 'Egypt',
                'name_ar' => 'مصر',
                'iso_code' => 'EG',
                'currency_name' => 'Egyptian Pound',
                'currency_code' => 'EGP',
                'status' => true,
            ]),
            2 => Country::create([
                'name_en' => 'United States',
                'name_ar' => 'الولايات المتحدة',
                'iso_code' => 'US',
                'currency_name' => 'US Dollar',
                'currency_code' => 'USD',
                'status' => true,
            ]),
            3 => Country::create([
                'name_en' => 'France',
                'name_ar' => 'فرنسا',
                'iso_code' => 'FR',
                'currency_name' => 'Euro',
                'currency_code' => 'EUR',
                'status' => true,
            ]),
            5 => Country::create([
                'name_en' => 'Saudi Arabia',
                'name_ar' => 'السعودية',
                'iso_code' => 'SA',
                'currency_name' => 'Saudi Riyal',
                'currency_code' => 'SAR',
                'status' => true,
            ]),
            6 => Country::create([
                'name_en' => 'Palestine',
                'name_ar' => 'فلسطين',
                'iso_code' => 'PS',
                'currency_name' => 'Israeli Shekel',
                'currency_code' => 'ILS',
                'status' => true,
            ]),
        ];
    }

    public function test_spa_panel_payload_updates_plan_with_country_prices(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'golden plan',
            'slug' => 'golden-plan',
            'description' => 'old description',
            'price' => 400,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        SubscriptionPlan::create([
            'name' => 'golden plan legacy',
            'slug' => 'golden-plan-legacy',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => false,
        ]);

        $token = $this->admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/subscription-plans/{$plan->id}", $this->spaPanelPayload());

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'golden plan')
            ->assertJsonPath('data.is_active', false);

        $plan->refresh();
        $this->assertSame('golden-plan', $plan->slug);
        $this->assertSame('golden plan descriptio', $plan->description);
        $this->assertSame('monthly', $plan->billing_cycle);
        $this->assertFalse($plan->is_active);
        $this->assertCount(5, $plan->countryPrices);

        $egyptPrice = $plan->countryPrices->firstWhere('country_id', $this->countries[1]->id);
        $this->assertNotNull($egyptPrice);
        $this->assertSame(10.0, (float) $egyptPrice->price);
        $this->assertSame('EG', $egyptPrice->country_code);
        $this->assertSame('EGP', $egyptPrice->currency_code);
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
            'duration' => 30,
            'price' => 130,
            'status' => true,
            'country_prices' => [
                [
                    'country_id' => $this->countries[1]->id,
                    'price' => 130,
                    'is_active' => true,
                    'can_subscribe' => true,
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
            'duration' => 30,
            'price' => 120,
            'status' => true,
            'country_prices' => [
                [
                    'country_id' => $this->countries[1]->id,
                    'price' => 120,
                    'is_active' => true,
                    'can_subscribe' => true,
                ],
            ],
        ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertSame('premium-1', $plan->slug);
    }

    public function test_subscription_plan_prices_alias_is_accepted_when_country_prices_missing(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Alias Plan',
            'slug' => 'alias-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $payload = $this->spaPanelPayload();
        unset($payload['country_prices']);
        $payload['name'] = 'Alias Plan Updated';
        $payload['subscription_plan_prices'] = $this->countryPriceRows();
        $payload['prices_by_country'] = $this->countryPriceRows();

        $token = $this->admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/subscription-plans/{$plan->id}", $payload);

        $response->assertOk();
        $this->assertCount(5, SubscriptionPlanPrice::where('plan_id', $plan->id)->get());
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

    /**
     * @return array<string, mixed>
     */
    private function spaPanelPayload(): array
    {
        return [
            'name' => 'golden plan',
            'description' => 'golden plan descriptio',
            'features' => ['ميزه واحد'],
            'status' => false,
            'is_active' => false,
            'is_featured' => false,
            'sort_order' => 0,
            'can_subscribe' => true,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'duration' => '30',
            'currency' => 'USD',
            'price' => 500,
            'country_prices' => $this->countryPriceRows(),
            'subscription_plan_prices' => $this->countryPriceRows(),
            'countries' => [
                $this->countries[1]->id,
                $this->countries[2]->id,
                $this->countries[3]->id,
                $this->countries[5]->id,
                $this->countries[6]->id,
            ],
            'prices_by_country' => $this->countryPriceRows(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function countryPriceRows(): array
    {
        return [
            [
                'country_id' => $this->countries[1]->id,
                'country_name' => 'مصر',
                'country_code' => 'EG',
                'currency_code' => 'EGP',
                'price' => 10,
                'is_active' => true,
                'can_subscribe' => true,
                'country' => $this->countries[1]->id,
            ],
            [
                'country_id' => $this->countries[2]->id,
                'country_name' => 'الولايات المتحدة',
                'country_code' => 'US',
                'currency_code' => 'USD',
                'price' => 100,
                'is_active' => true,
                'can_subscribe' => true,
                'country' => $this->countries[2]->id,
            ],
            [
                'country_id' => $this->countries[3]->id,
                'country_name' => 'فرنسا',
                'country_code' => 'FR',
                'currency_code' => 'EUR',
                'price' => 250,
                'is_active' => true,
                'can_subscribe' => true,
                'country' => $this->countries[3]->id,
            ],
            [
                'country_id' => $this->countries[5]->id,
                'country_name' => 'السعودية',
                'country_code' => 'SA',
                'currency_code' => 'SAR',
                'price' => 200,
                'is_active' => true,
                'can_subscribe' => true,
                'country' => $this->countries[5]->id,
            ],
            [
                'country_id' => $this->countries[6]->id,
                'country_name' => 'فلسطين',
                'country_code' => 'PS',
                'currency_code' => 'ILS',
                'price' => 700,
                'is_active' => true,
                'can_subscribe' => true,
                'country' => $this->countries[6]->id,
            ],
        ];
    }
}
