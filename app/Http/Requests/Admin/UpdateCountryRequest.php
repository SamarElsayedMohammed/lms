<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'iso_code' => 'required|string|size:2|unique:countries,iso_code,' . $this->route('id'),
            'currency_name' => 'nullable|string|max:255',
            'currency_code' => 'nullable|string|size:3',
            'status' => 'nullable|boolean',
        ];
    }
}
