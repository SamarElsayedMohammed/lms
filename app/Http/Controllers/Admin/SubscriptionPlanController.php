<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\Country;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SubscriptionPlanController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['subscription-plans-list', 'manage_plans']);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->getTableData($request);
        }

        return view('admin.subscription-plans.index', [
            'type_menu' => 'subscription-plans',
            'billingCycles' => SubscriptionPlan::BILLING_CYCLES,
        ]);
    }

    private function getTableData(Request $request): \Illuminate\Http\JsonResponse
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
        }
        ));

        $query->orderBy($sort, strtoupper($order));
        $total = $query->count();
        $result = $query->skip($offset)->take($limit)->get();

        $rows = [];
        $no = $offset + 1;

        foreach ($result as $row) {
            $operate = '';
            if ($showDeleted == 1 || $showDeleted === '1') {
                if (auth()->user()->can('subscription-plans-restore')) {
                    $operate .= BootstrapTableService::restoreButton(route('subscription-plans.restore', $row->id));
                }
                if (auth()->user()->can('subscription-plans-trash')) {
                    $operate .= BootstrapTableService::trashButton(route('subscription-plans.trash', $row->id));
                }
            }
            else {
                if (auth()->user()->can('subscription-plans-list')) {
                    $operate .= BootstrapTableService::button(
                        'fa fa-eye',
                        route('subscription-plans.show', $row->id),
                    ['btn-info'],
                    ['title' => __('View')]
                    );
                }
                if (auth()->user()->can('subscription-plans-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('subscription-plans.edit', $row->id),
                        false,
                        null,
                        null,
                        null,
                        'fa fa-edit'
                    );
                }
                if (auth()->user()->can('subscription-plans-toggle')) {
                    $operate .= BootstrapTableService::button(
                        $row->is_active ? 'fa fa-toggle-on' : 'fa fa-toggle-off',
                        route('subscription-plans.toggle', $row->id),
                    ['btn-secondary', 'toggle-status'],
                    [
                        'title' => $row->is_active ? __('Deactivate') : __('Activate'),
                        'data-id' => $row->id,
                        'data-active' => $row->is_active ? '1' : '0',
                    ]
                    );
                }
                if (auth()->user()->can('subscription-plans-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('subscription-plans.destroy', $row->id));
                }
            }

            $rows[] = [
                'id' => $row->id,
                'no' => $no++,
                'name' => $row->name,
                'slug' => $row->slug,
                'price' => (float)$row->price,
                'price_formatted' => $row->formatted_price,
                'billing_cycle' => $row->billing_cycle,
                'billing_cycle_label' => $row->billing_cycle_label,
                'commission_rate' => (float)$row->commission_rate,
                'subscribers_count' => $row->subscriptions_count ?? 0,
                'active_subscribers_count' => $row->active_subscriptions_count ?? 0,
                'is_active' => (int)$row->is_active,
                'is_active_display' => $row->is_active ? __('Active') : __('Inactive'),
                'sort_order' => $row->sort_order,
                'operate' => $operate,
            ];
        }

        return response()->json(['total' => $total, 'rows' => $rows]);
    }

    public function create()
    {
        ResponseService::noAnyPermissionThenRedirect(['subscription-plans-create', 'manage_plans']);

        $countries = Country::active()->orderBy('name_ar')->get();

        return view('admin.subscription-plans.create', [
            'type_menu' => 'subscription-plans',
            'billingCycles' => SubscriptionPlan::BILLING_CYCLES,
            'countries' => $countries,
        ]);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-create');

        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,yearly,lifetime,custom',
            'duration_days' => 'required_if:billing_cycle,custom|nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            // Country prices validation
            'countries' => 'required|array|min:1',
            'countries.*.country_id' => 'required|exists:countries,id,status,1',
            'countries.*.price' => 'required|numeric|min:0',
            'countries.*.offer_price' => 'nullable|numeric|min:0',
        ];

        $messages = [
            'countries.required' => __('يجب اختيار دولة واحدة على الأقل'),
            'countries.min' => __('يجب اختيار دولة واحدة على الأقل'),
            'countries.*.country_id.required' => __('يجب اختيار الدولة'),
            'countries.*.country_id.exists' => __('الدولة المختارة غير صالحة أو غير مفعلة'),
            'countries.*.price.required' => __('السعر الأساسي مطلوب لكل دولة'),
            'countries.*.price.min' => __('السعر الأساسي يجب أن يكون 0 أو أكثر'),
            'countries.*.offer_price.min' => __('سعر العرض يجب أن يكون 0 أو أكثر'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        // Custom validation: offer_price must be less than price per country
        $validator->after(function ($validator) use ($request) {
            $countries = $request->input('countries', []);
            $seenCountryIds = [];
            foreach ($countries as $index => $entry) {
                $countryId = $entry['country_id'] ?? null;
                // duplicate country check
                if ($countryId && in_array($countryId, $seenCountryIds)) {
                    $validator->errors()->add("countries.{$index}.country_id", __('لا يجوز تكرار نفس الدولة'));
                }
                $seenCountryIds[] = $countryId;
                // offer_price < price check
                $price = isset($entry['price']) ? (float)$entry['price'] : 0;
                $offerPrice = isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null
                    ? (float)$entry['offer_price'] : null;
                if ($offerPrice !== null && $offerPrice >= $price) {
                    $validator->errors()->add("countries.{$index}.offer_price", __('سعر العرض يجب أن يكون أقل من السعر الأساسي'));
                }
            }
        });

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::validationError($validator->errors()->first());
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $data['slug'] = Str::slug($data['name']);
            $data['duration_days'] = $this->resolveDurationDays($data);
            $data['commission_rate'] = $data['commission_rate'] ?? 0;
            $data['sort_order'] = $data['sort_order'] ?? 0;
            $data['is_active'] = $request->boolean('is_active', true);

            // Remove countries from plan data before create
            $countriesData = $data['countries'];
            unset($data['countries']);

            $plan = SubscriptionPlan::create($data);

            // Save country prices
            foreach ($countriesData as $entry) {
                SubscriptionPlanPrice::create([
                    'plan_id' => $plan->id,
                    'country_id' => $entry['country_id'],
                    'price' => $entry['price'],
                    'offer_price' => (isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null)
                    ? (float)$entry['offer_price'] : null,
                ]);
            }

            DB::commit();

            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::successResponse(__('Subscription plan created successfully'));
            }
            return redirect()->route('subscription-plans.index')
                ->with('success', __('Subscription plan created successfully'));
        }
        catch (Exception $e) {
            DB::rollBack();
            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::errorResponse($e->getMessage());
            }
            return redirect()->back()->withErrors(['message' => $e->getMessage()])->withInput();
        }
    }

    public function show(SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noAnyPermissionThenRedirect(['subscription-plans-list', 'manage_plans']);

        $subscriptionPlan->loadCount(['subscriptions', 'activeSubscriptions']);
        $subscribers = $subscriptionPlan->subscriptions()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.subscription-plans.show', [
            'plan' => $subscriptionPlan,
            'subscribers' => $subscribers,
            'type_menu' => 'subscription-plans',
        ]);
    }

    public function edit(SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noAnyPermissionThenRedirect(['subscription-plans-edit', 'manage_plans']);

        $countryPrices = $subscriptionPlan->countryPrices()->get()->keyBy('country_id');
        $countries = Country::active()->orderBy('name_ar')->get();

        return view('admin.subscription-plans.edit', [
            'plan' => $subscriptionPlan,
            'type_menu' => 'subscription-plans',
            'billingCycles' => SubscriptionPlan::BILLING_CYCLES,
            'countries' => $countries,
            'countryPrices' => $countryPrices,
        ]);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-edit');

        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,yearly,lifetime,custom',
            'duration_days' => 'required_if:billing_cycle,custom|nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            // Country prices validation
            'countries' => 'required|array|min:1',
            'countries.*.country_id' => 'required|exists:countries,id,status,1',
            'countries.*.price' => 'required|numeric|min:0',
            'countries.*.offer_price' => 'nullable|numeric|min:0',
        ];

        $messages = [
            'countries.required' => __('يجب اختيار دولة واحدة على الأقل'),
            'countries.min' => __('يجب اختيار دولة واحدة على الأقل'),
            'countries.*.country_id.required' => __('يجب اختيار الدولة'),
            'countries.*.country_id.exists' => __('الدولة المختارة غير صالحة أو غير مفعلة'),
            'countries.*.price.required' => __('السعر الأساسي مطلوب لكل دولة'),
            'countries.*.price.min' => __('السعر الأساسي يجب أن يكون 0 أو أكثر'),
            'countries.*.offer_price.min' => __('سعر العرض يجب أن يكون 0 أو أكثر'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        // Custom validation: offer_price must be less than price, no duplicate countries
        $validator->after(function ($validator) use ($request) {
            $countries = $request->input('countries', []);
            $seenCountryIds = [];
            foreach ($countries as $index => $entry) {
                $countryId = $entry['country_id'] ?? null;
                if ($countryId && in_array($countryId, $seenCountryIds)) {
                    $validator->errors()->add("countries.{$index}.country_id", __('لا يجوز تكرار نفس الدولة'));
                }
                $seenCountryIds[] = $countryId;
                $price = isset($entry['price']) ? (float)$entry['price'] : 0;
                $offerPrice = isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null
                    ? (float)$entry['offer_price'] : null;
                if ($offerPrice !== null && $offerPrice >= $price) {
                    $validator->errors()->add("countries.{$index}.offer_price", __('سعر العرض يجب أن يكون أقل من السعر الأساسي'));
                }
            }
        });

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::validationError($validator->errors()->first());
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $data['slug'] = Str::slug($data['name']);
            $data['duration_days'] = $this->resolveDurationDays($data);
            $data['commission_rate'] = $data['commission_rate'] ?? 0;
            $data['sort_order'] = $data['sort_order'] ?? 0;
            $data['is_active'] = $request->boolean('is_active', true);

            $countriesData = $data['countries'];
            unset($data['countries']);

            $subscriptionPlan->update($data);

            // Sync country prices: collect submitted country_ids
            $submittedCountryIds = collect($countriesData)->pluck('country_id')->toArray();

            // Delete removed countries
            SubscriptionPlanPrice::where('plan_id', $subscriptionPlan->id)
                ->whereNotIn('country_id', $submittedCountryIds)
                ->delete();

            // Update or create country prices
            foreach ($countriesData as $entry) {
                SubscriptionPlanPrice::updateOrCreate(
                [
                    'plan_id' => $subscriptionPlan->id,
                    'country_id' => $entry['country_id'],
                ],
                [
                    'price' => $entry['price'],
                    'offer_price' => (isset($entry['offer_price']) && $entry['offer_price'] !== '' && $entry['offer_price'] !== null)
                    ? (float)$entry['offer_price'] : null,
                ]
                );
            }

            DB::commit();

            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::successResponse(__('Subscription plan updated successfully'));
            }
            return redirect()->route('subscription-plans.index')
                ->with('success', __('Subscription plan updated successfully'));
        }
        catch (Exception $e) {
            DB::rollBack();
            if ($request->ajax() || $request->wantsJson()) {
                return ResponseService::errorResponse($e->getMessage());
            }
            return redirect()->back()->withErrors(['message' => $e->getMessage()])->withInput();
        }
    }

    public function destroy(SubscriptionPlan $subscriptionPlan)
    {
        ResponseService::noPermissionThenSendJson('subscription-plans-delete');

        try {
            $subscriptionPlan->delete();
            return ResponseService::successResponse(__('Subscription plan deleted successfully'));
        }
        catch (Exception $e) {
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
        }
        catch (Exception $e) {
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
        }
        catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function updateSortOrder(Request $request, int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['subscription-plans-edit', 'manage_plans']);

        $validator = Validator::make($request->all(), ['sort_order' => 'required|integer|min:0']);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            $plan = SubscriptionPlan::findOrFail($id);
            $plan->sort_order = (int)$request->sort_order;
            $plan->save();
            return ResponseService::successResponse(__('Sort order updated'));
        }
        catch (Exception $e) {
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
        }
        catch (Exception $e) {
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