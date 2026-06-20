<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreSubscriptionPlanPanelRequest;
use App\Http\Resources\Admin\SubscriptionPlanAdminResource;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SubscriptionPlanAdminApiController extends AdminCrudApiController
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlanService,
    ) {
        $this->middleware('auth:sanctum');
    }

    public function store(StoreSubscriptionPlanPanelRequest $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('subscription-plans-create');

        // No need for default country id anymore as prices are passed via country_prices payload

        try {
            $validated = $request->validated();
            $validated['status'] = $request->boolean('status', true);

            $plan = $this->subscriptionPlanService->createFromPanelPayload($validated);

            return $this->jsonSuccess(
                __('Subscription plan created successfully'),
                new SubscriptionPlanAdminResource($plan),
                201,
            );
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    public function update(StoreSubscriptionPlanPanelRequest $request, \App\Models\SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('subscription-plans-edit');

        try {
            $validated = $request->validated();
            $validated['status'] = $request->boolean('status', true);

            // Temporarily use create/delete strategy for country prices in service
            $plan = DB::transaction(function () use ($subscriptionPlan, $validated) {
                // Keep the ID and basic data, but update everything
                $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
                if ($validated['slug'] === '') {
                    $validated['slug'] = 'plan-' . \Illuminate\Support\Str::random(10);
                }

                $validated['duration_days'] = (int) $validated['duration'];
                $validated['commission_type'] = $validated['commission_type'] ?? 'percentage';
                $validated['commission_rate'] = $validated['commission_rate'] ?? 0;
                $validated['is_active'] = $validated['status'];

                $countryPrices = $validated['country_prices'] ?? [];
                unset($validated['country_prices']);

                $subscriptionPlan->update($validated);

                // Delete old country prices and recreate
                $subscriptionPlan->countryPrices()->delete();

                foreach ($countryPrices as $entry) {
                    \App\Models\SubscriptionPlanPrice::create([
                        'plan_id' => $subscriptionPlan->id,
                        'country_id' => $entry['country_id'],
                        'country_code' => $entry['country_code'],
                        'currency_code' => $entry['currency_code'] ?? 'EGP',
                        'price' => $entry['price'],
                        'old_price' => (isset($entry['old_price']) && $entry['old_price'] !== '' && $entry['old_price'] !== null)
                            ? (float) $entry['old_price'] : null,
                        'is_active' => $entry['is_active'] ?? true,
                        'can_subscribe' => $entry['can_subscribe'] ?? true,
                    ]);
                    \App\Models\SupportedCurrency::ensureCurrencyExists($entry['country_code'], $entry['currency_code'] ?? null);
                }

                return $subscriptionPlan->fresh('countryPrices');
            });

            return $this->jsonSuccess(
                __('Subscription plan updated successfully'),
                new SubscriptionPlanAdminResource($plan)
            );
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
}
