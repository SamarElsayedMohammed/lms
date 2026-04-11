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

        $countryId = $this->subscriptionPlanService->resolveDefaultCountryId($request->input('currency'));
        if ($countryId === null) {
            return $this->jsonError(
                __('No active country is configured. Add a country in admin.'),
                422,
            );
        }

        try {
            $validated = $request->validated();
            $validated['status'] = $request->boolean('status', true);

            $plan = $this->subscriptionPlanService->createFromPanelPayload($validated, $countryId);

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
