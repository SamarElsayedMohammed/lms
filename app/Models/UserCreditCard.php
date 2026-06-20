<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCreditCard extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'card_holder_name',
        'last_four_digits',
        'brand',
        'exp_month',
        'exp_year',
        'gateway_token',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $appends = ['masked_number'];
    
    protected $hidden = [
        'gateway_token', // Never expose gateway token to API responses by default
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getMaskedNumberAttribute()
    {
        return '**** **** **** ' . $this->last_four_digits;
    }
}
