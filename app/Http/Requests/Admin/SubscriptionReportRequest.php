<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SubscriptionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'preset' => $this->input('preset', '30d'),
            'status' => $this->input('status', 'all'),
            'country' => $this->filled('country')
                ? strtoupper(trim((string) $this->input('country')))
                : null,
            'payment_method' => $this->filled('payment_method')
                ? trim((string) $this->input('payment_method'))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'preset' => ['required', Rule::in(['today', '7d', '30d', '90d', '12m', 'this_month', 'last_month', 'this_year', 'all', 'all_time', 'custom'])],
            'date_from' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['required', Rule::in(['all', 'active', 'expired', 'cancelled', 'pending', 'pending_approval'])],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('preset') !== 'custom' || !$this->filled(['date_from', 'date_to'])) {
                return;
            }

            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            try {
                $from = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('date_from'));
                $to = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('date_to'));
            } catch (\Throwable) {
                return;
            }

            if ($from !== false && $to !== false && $from->diffInDays($to) > 730) {
                $validator->errors()->add('date_to', 'The report period may not exceed 730 days.');
            }
        });
    }

    public function filters(): array
    {
        return $this->validated();
    }
}
