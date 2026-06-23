<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Plan basic info
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'currency' => 'nullable|string|max:32',
            'duration' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'can_subscribe' => 'nullable|boolean',

            // Commission settings
            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_rate' => 'nullable|numeric|min:0',

            // Features list
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',

            // Country-specific pricing
            // country_id is the ONLY required field - backend derives country_code and currency_code
            'country_prices' => 'nullable|array',
            'country_prices.*.country_id' => 'required|integer|exists:countries,id',
            'country_prices.*.country_code' => 'nullable|string|size:2', // Optional - derived from country_id
            'country_prices.*.currency_code' => 'nullable|string|size:3', // Optional - derived from country_id
            'country_prices.*.price' => 'required|numeric|min:0',
            'country_prices.*.old_price' => 'nullable|numeric|min:0',
            'country_prices.*.is_active' => 'nullable|boolean',
            'country_prices.*.can_subscribe' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
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
     * Get custom attributes for validator errors.
     *
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
