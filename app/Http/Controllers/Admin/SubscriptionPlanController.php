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

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-create');

        $rules = [
            'name' => 'required|string|max:255|unique:subscription_plans,name',
            'description' => 'nullable|string',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,yearly,custom',
            'duration_days' => 'required_if:billing_cycle,custom|nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'countries' => 'required|array|min:1',
            'countries.*.country_id' => 'required|exists:countries,id,status,1',
            'countries.*.country_code' => 'nullable|string',
            'countries.*.currency_code' => 'nullable|string|max:10',
            'countries.*.price' => 'required|numeric|min:0',
            'countries.*.offer_price' => 'nullable|numeric|min:0',
        ];

        $messages = [
            'countries.required' => __('validation.countries_required'),
            'countries.min' => __('validation.countries_min_1'),
            'countries.*.country_id.required' => __('validation.country_id_required'),
            'countries.*.country_id.exists' => __('validation.country_id_exists'),
            'countries.*.price.required' => __('validation.country_price_required'),
            'countries.*.price.min' => __('validation.country_price_min'),
            'countries.*.offer_price.min' => __('validation.country_offer_price_min'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        $validator->after(function ($validator) use ($request) {
            $countries = $request->input('countries', []);
            $seenCountryIds = [];
            foreach ($countries as $index => $entry) {
                $countryId = $entry['country_id'] ?? null;
                if ($countryId && in_array($countryId, $seenCountryIds)) {
                    $validator->errors()->add("countries.{$index}.country_id", __('validation.country_duplicate'));
                }
                $seenCountryIds[] = $countryId;
                $price = isset($entry['price']) ? (float)$entry['price'] : 0;
                $offerPrice = isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null
                    ? (float)$entry['offer_price'] : null;
                if ($offerPrice !== null && $offerPrice >= $price) {
                    $validator->errors()->add("countries.{$index}.offer_price", __('validation.offer_price_must_be_less_than_price'));
                }
            }
        });

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $data['slug'] = $this->subscriptionPlanService->uniqueSlugForName($data['name']);
            $data['duration_days'] = $this->resolveDurationDays($data);
            $data['commission_rate'] = $data['commission_rate'] ?? 0;
            $data['sort_order'] = $data['sort_order'] ?? 0;
            $data['is_active'] = $request->boolean('is_active', true);

            $countriesData = $data['countries'];
            unset($data['countries']);

            $plan = SubscriptionPlan::create($data);

            foreach ($countriesData as $entry) {
                SubscriptionPlanPrice::create([
                    'plan_id' => $plan->id,
                    'country_id' => $entry['country_id'],
                    'country_code' => $entry['country_code'] ?? null,
                    'currency_code' => $entry['currency_code'] ?? 'USD', // fallback to USD if not provided
                    'price' => $entry['price'],
                    'offer_price' => (isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null)
                    ? (float)$entry['offer_price'] : null,
                ]);
            }

            DB::commit();

            return ResponseService::successResponse(__('Subscription plan created successfully'), $plan->load('countryPrices'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            return ResponseService::errorResponse($e->getMessage());
        }
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

    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-edit');

        $rules = [
            'name' => 'required|string|max:255|unique:subscription_plans,name,' . $subscriptionPlan->id,
            'description' => 'nullable|string',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,yearly,custom',
            'duration_days' => 'required_if:billing_cycle,custom|nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'countries' => 'required|array|min:1',
            'countries.*.country_id' => 'required|exists:countries,id,status,1',
            'countries.*.country_code' => 'nullable|string',
            'countries.*.currency_code' => 'nullable|string|max:10',
            'countries.*.price' => 'required|numeric|min:0',
            'countries.*.offer_price' => 'nullable|numeric|min:0',
        ];

        $messages = [
            'countries.required' => __('validation.countries_required'),
            'countries.min' => __('validation.countries_min_1'),
            'countries.*.country_id.required' => __('validation.country_id_required'),
            'countries.*.country_id.exists' => __('validation.country_id_exists'),
            'countries.*.price.required' => __('validation.country_price_required'),
            'countries.*.price.min' => __('validation.country_price_min'),
            'countries.*.offer_price.min' => __('validation.country_offer_price_min'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        $validator->after(function ($validator) use ($request) {
            $countries = $request->input('countries', []);
            $seenCountryIds = [];
            foreach ($countries as $index => $entry) {
                $countryId = $entry['country_id'] ?? null;
                if ($countryId && in_array($countryId, $seenCountryIds)) {
                    $validator->errors()->add("countries.{$index}.country_id", __('validation.country_duplicate'));
                }
                $seenCountryIds[] = $countryId;
                $price = isset($entry['price']) ? (float)$entry['price'] : 0;
                $offerPrice = isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null
                    ? (float)$entry['offer_price'] : null;
                if ($offerPrice !== null && $offerPrice >= $price) {
                    $validator->errors()->add("countries.{$index}.offer_price", __('validation.offer_price_must_be_less_than_price'));
                }
            }
        });

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            if ($data['name'] !== $subscriptionPlan->name) {
                $data['slug'] = $this->subscriptionPlanService->uniqueSlugForName($data['name'], $subscriptionPlan->id);
            } else {
                $data['slug'] = $subscriptionPlan->slug;
            }
            $data['duration_days'] = $this->resolveDurationDays($data);
            $data['commission_rate'] = $data['commission_rate'] ?? 0;
            $data['sort_order'] = $data['sort_order'] ?? 0;
            $data['is_active'] = $request->boolean('is_active', true);

            $countriesData = $data['countries'];
            unset($data['countries']);

            $subscriptionPlan->update($data);

            $submittedCountryIds = collect($countriesData)->pluck('country_id')->toArray();

            SubscriptionPlanPrice::where('plan_id', $subscriptionPlan->id)
                ->whereNotIn('country_id', $submittedCountryIds)
                ->delete();

            foreach ($countriesData as $entry) {
                SubscriptionPlanPrice::updateOrCreate(
                [
                    'plan_id' => $subscriptionPlan->id,
                    'country_id' => $entry['country_id'],
                ],
                [
                    'country_code' => $entry['country_code'] ?? null,
                    'currency_code' => $entry['currency_code'] ?? 'USD', // fallback to USD if not provided
                    'price' => $entry['price'],
                    'offer_price' => (isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null)
                    ? (float)$entry['offer_price'] : null,
                ]
                );
            }

            DB::commit();

            return ResponseService::successResponse(__('Subscription plan updated successfully'), $subscriptionPlan->fresh('countryPrices'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            return ResponseService::errorResponse($e->getMessage());
        }
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
