<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreSubscriptionPlanPanelRequest;
use App\Http\Resources\Admin\SubscriptionPlanAdminResource;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            if (!$request->has('status') && $request->has('is_active')) {
                $validated['status'] = $request->boolean('is_active');
            } else {
                $validated['status'] = $request->boolean('status', true);
            }

            $plan = $this->subscriptionPlanService->updateFromPanelPayload($subscriptionPlan, $validated);

            return $this->jsonSuccess(
                __('Subscription plan updated successfully'),
                new SubscriptionPlanAdminResource($plan)
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
}
