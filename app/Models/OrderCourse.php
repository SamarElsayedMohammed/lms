<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'course_id',
        'promo_code_id',
        'price',
        'discount_price',
        'discount_amount',
        'tax_price',
        'certificate_purchased',
        'certificate_fee',
        'certificate_purchased_at',
    ];

    protected $casts = [
        'certificate_purchased' => 'boolean',
        'certificate_purchased_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function course()
    {
        return $this->belongsTo(Course\Course::class);
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }
}
