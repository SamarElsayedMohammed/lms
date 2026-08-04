<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => [
                'nullable',
                'string',
                Rule::in(['7_days', '30_days', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'custom']),
            ],
            'date_range' => [
                'nullable',
                'string',
                Rule::in(['7_days', '30_days', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'custom']),
            ],
            'from' => ['required_if:period,custom', 'required_if:date_range,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'required_if:date_range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $period = $this->input('period');
            $dateRange = $this->input('date_range');

            if ($period !== null && $dateRange !== null && $period !== $dateRange) {
                $validator->errors()->add(
                    'date_range',
                    'The date_range must match period when both parameters are provided.'
                );
            }
        });
    }
}
