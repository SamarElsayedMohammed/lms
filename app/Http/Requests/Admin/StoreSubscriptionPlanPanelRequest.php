<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload shape used by the SPA admin (e.g. skillso) at POST .../admin/subscription-plan
 */
final class StoreSubscriptionPlanPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'currency' => 'nullable|string|max:32',
            'duration' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'can_subscribe' => 'nullable|boolean',
            'commission_type' => 'nullable|in:percentage,fixed',
            'commission_rate' => 'nullable|numeric|min:0',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:500',
        ];
    }
}
