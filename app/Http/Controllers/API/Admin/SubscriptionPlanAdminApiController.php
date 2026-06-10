<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreSubscriptionPlanPanelRequest;
use App\Http\Resources\Admin\SubscriptionPlanAdminResource;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
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
}
