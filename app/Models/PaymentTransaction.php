<?php

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'amount',
        'payment_gateway',
        'payment_type',
        'order_id',
        'transaction_id',
        'payment_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
