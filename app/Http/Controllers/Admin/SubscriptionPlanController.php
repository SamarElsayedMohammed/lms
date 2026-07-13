<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\Country;
use App\Services\ResponseService;
use App\Services\SubscriptionPlanService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SubscriptionPlanController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanService $subscriptionPlanService,
    ) {
    }
    public function index(Request $request)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-list');

        $offset = (int)$request->input('offset', 0);
        $limit = (int)$request->input('limit', 10);
        $sort = $request->input('sort', 'sort_order');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $query = SubscriptionPlan::query()
            ->withCount(['subscriptions', 'activeSubscriptions'])
            ->when($showDeleted == 1 || $showDeleted === '1', fn($q) => $q->onlyTrashed())
            ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }));

        $query->orderBy($sort, strtoupper($order));
        $total = $query->count();
        $result = $query->skip($offset)->take($limit)->get();

        return response()->json(['total' => $total, 'rows' => $result]);
    }



    public function show(SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-list');

        $subscriptionPlan->loadCount(['subscriptions', 'activeSubscriptions']);
        $subscribers = $subscriptionPlan->subscriptions()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        return ResponseService::successResponse(__('Success'), [
            'plan' => $subscriptionPlan,
            'subscribers' => $subscribers,
            'country_prices' => $subscriptionPlan->countryPrices,
        ]);
    }



    public function destroy(SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-delete');

        try {
            $subscriptionPlan->delete();
            return ResponseService::successResponse(__('Subscription plan deleted successfully'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function restore(int $id)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-restore');

        try {
            $plan = SubscriptionPlan::onlyTrashed()->findOrFail($id);
            $plan->restore();
            return ResponseService::successResponse(__('Subscription plan restored successfully'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function trash(int $id)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-trash');

        try {
            $plan = SubscriptionPlan::onlyTrashed()->findOrFail($id);
            $plan->forceDelete();
            return ResponseService::successResponse(__('Subscription plan permanently deleted'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function updateSortOrder(Request $request)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-edit');

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:subscription_plans,id',
            'items.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::transaction(function () use ($request): void {
                foreach ($request->input('items', []) as $item) {
                    SubscriptionPlan::whereKey((int) $item['id'])
                        ->update(['sort_order' => (int) $item['order']]);
                }
            });

            return ResponseService::successResponse(__('Sort order updated'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function toggleStatus(int $id)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-toggle');

        try {
            $subscriptionPlan = SubscriptionPlan::findOrFail($id);
            $subscriptionPlan->is_active = !$subscriptionPlan->is_active;
            $subscriptionPlan->save();
            return ResponseService::successResponse(
                $subscriptionPlan->is_active ? __('Plan activated') : __('Plan deactivated'),
            ['is_active' => $subscriptionPlan->is_active]
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    private function resolveDurationDays(array $data): ?int
    {
        if (($data['billing_cycle'] ?? '') === 'custom') {
            return isset($data['duration_days']) ? (int)$data['duration_days'] : null;
        }
        if (($data['billing_cycle'] ?? '') === 'lifetime') {
            return null;
        }
        return SubscriptionPlan::CYCLE_DAYS[$data['billing_cycle']] ?? null;
    }
}
