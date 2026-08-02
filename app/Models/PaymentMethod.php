<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
        'logo',
        'account_name',
        'account_number',
        'bank_name',
        'iban',
        'instapay_id',
        'merchant_code',
        'instructions',
        'dynamic_fields',
        'sort_order',
        'countries',
        'currencies',
        'require_receipt',
        'min_amount',
        'max_amount',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'require_receipt' => 'boolean',
        'dynamic_fields' => 'array',
        'countries' => 'array',
        'currencies' => 'array',
        'sort_order' => 'integer',
        'min_amount' => 'float',
        'max_amount' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name) . '-' . uniqid();
            }
        });
    }

    public function getLogoAttribute($value)
    {
        if ($value) {
            return \App\Services\FileService::getFileUrl($value);
        }
        return null;
    }

    public function toStructuredAccountDetails(): array
    {
        return [
            'type' => $this->type,
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'bank_name' => $this->bank_name,
            'iban' => $this->iban,
            'instapay_id' => $this->instapay_id,
            'merchant_code' => $this->merchant_code,
            'additional_details' => [],
        ];
    }
}

