<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for creating/updating subscription plans via the SPA admin panel
 *
 * Country Prices Validation:
 * - country_id is REQUIRED and must exist in the countries table
 * - country_code is OPTIONAL - will be auto-derived from country_id if not provided
 * - currency_code is OPTIONAL - will be auto-derived from country_id if not provided
 *
 * The backend ensures database integrity by always deriving country_code and currency_code
 * from the Countries table, regardless of what the frontend sends. This prevents:
 * - Mismatched country/currency combinations
 * - Frontend values overriding database values
 * - Data inconsistency issues
 */
final class StoreSubscriptionPlanPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('country_prices')) {
            foreach (['subscription_plan_prices', 'prices_by_country'] as $field) {
                if ($this->filled($field)) {
                    $this->merge(['country_prices' => $this->input($field)]);
                    break;
                }
            }
        }

        if (!$this->filled('duration') && $this->filled('duration_days')) {
            $this->merge(['duration' => $this->input('duration_days')]);
        }

        if (!$this->has('status') && $this->has('is_active')) {
            $this->merge(['status' => $this->boolean('is_active')]);
        }

        // Strip country_code/currency_code from country_prices entries before validation.
        // These fields are ALWAYS derived from the database in the service layer,
        // so frontend values are irrelevant and should never cause validation failures.
        $countryPrices = $this->input('country_prices', []);
        if (is_array($countryPrices)) {
            $countryPrices = array_map(function ($entry) {
                unset($entry['country_code'], $entry['currency_code']);
                return $entry;
            }, $countryPrices);
            $this->merge(['country_prices' => $countryPrices]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plan = $this->route('subscription_plan') ?? $this->route('subscriptionPlan');
        $planId = is_object($plan) ? $plan->id : $plan;

        $nameRule = 'required|string|max:255';
        if ($planId) {
            $nameRule .= '|unique:subscription_plans,name,' . $planId;
        } else {
            $nameRule .= '|unique:subscription_plans,name';
        }

        return [
            'name' => $nameRule,
            'description' => 'nullable|string',
            'currency' => 'nullable|string|max:32',
            'billing_cycle' => [
                'nullable',
                Rule::in(array_keys(SubscriptionPlan::BILLING_CYCLES)),
            ],
            'duration' => 'required|numeric|min:1',
            'duration_days' => 'nullable|integer|min:1',
            'price' => 'required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'can_subscribe' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',

            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_rate' => 'nullable|numeric|min:0',

            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',

            'country_prices' => 'nullable|array',
            'country_prices.*.country_id' => 'required|integer|exists:countries,id',
            'country_prices.*.price' => 'required|numeric|min:0',
            'country_prices.*.old_price' => 'nullable|numeric|min:0',
            'country_prices.*.is_active' => 'nullable|boolean',
            'country_prices.*.can_subscribe' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country_prices.*.country_id.required' => 'Country ID is required for each country price.',
            'country_prices.*.country_id.exists' => 'The selected country does not exist.',
            'country_prices.*.price.required' => 'Price is required for each country.',
            'country_prices.*.price.numeric' => 'Price must be a valid number.',
            'country_prices.*.price.min' => 'Price cannot be negative.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'country_prices.*.country_id' => 'country',
            'country_prices.*.price' => 'price',
            'country_prices.*.old_price' => 'old price',
        ];
    }
}
