<?php

namespace App\Models\Course;

use Illuminate\Database\Eloquent\Model;

class CourseCountryPrice extends Model
{
    protected $fillable = [
        'course_id',
        'country_code',
        'price_egp',
        'discount_price_egp',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_egp' => 'float',
        'discount_price_egp' => 'float',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function currency()
    {
        return $this->hasOne(\App\Models\SupportedCurrency::class, 'country_code', 'country_code');
    }}
