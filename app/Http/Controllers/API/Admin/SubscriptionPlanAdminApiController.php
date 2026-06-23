<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreSubscriptionPlanPanelRequest;
use App\Http\Resources\Admin\SubscriptionPlanAdminResource;
use App\Services\SubscriptionPlanPriceService;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin API Controller for Subscription Plans
 *
 * Handles CRUD operations for subscription plans including:
 * - Creating new plans with country-specific pricing
 * - Updating existing plans
 * - Managing country prices (auto-derives country_code and currency_code from database)
 */
final class SubscriptionPlanAdminApiController extends AdminCrudApiController
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlanService,
        private readonly SubscriptionPlanPriceService $priceService,
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Create a new subscription plan
     *
     * @param StoreSubscriptionPlanPanelRequest $request
     * @return JsonResponse
     */
    public function store(StoreSubscriptionPlanPanelRequest $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('subscription-plans-create');

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

    /**
     * Update an existing subscription plan
     *
     * Country Prices Handling:
     * - country_id is required, must exist in countries table
     * - country_code and currency_code are ALWAYS derived from the Countries table
     * - Frontend values for country_code/currency_code are ignored to ensure data integrity
     *
     * @param StoreSubscriptionPlanPanelRequest $request
     * @param \App\Models\SubscriptionPlan $subscriptionPlan
     * @return JsonResponse
     */
    public function update(StoreSubscriptionPlanPanelRequest $request, \App\Models\SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('subscription-plans-edit');

        try {
            $validated = $request->validated();
            $validated['status'] = $request->boolean('status', true);

            $plan = DB::transaction(function () use ($subscriptionPlan, $validated) {
                // Generate slug from name
                $validated['slug'] = Str::slug($validated['name']);
                if ($validated['slug'] === '') {
                    $validated['slug'] = 'plan-' . Str::random(10);
                }

                // Map duration to duration_days
                $validated['duration_days'] = (int) $validated['duration'];
                $validated['commission_type'] = $validated['commission_type'] ?? 'percentage';
                $validated['commission_rate'] = $validated['commission_rate'] ?? 0;
                $validated['is_active'] = $validated['status'];

                // Extract country prices before updating plan
                $countryPrices = $validated['country_prices'] ?? [];
                unset($validated['country_prices']);

                // Update the plan
                $subscriptionPlan->update($validated);

                // Sync country prices using the dedicated service
                // This ensures country_code and currency_code are always derived from the database
                $this->priceService->syncCountryPrices(
                    $subscriptionPlan,
                    $countryPrices,
                    removeOthers: true
                );

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
