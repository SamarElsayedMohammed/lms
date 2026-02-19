<?php

namespace App\Models;

use App\Models\Course\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'course_id',
        'instructor_id',
        'instructor_type',
        'course_price',
        'discounted_price',
        'admin_commission_rate',
        'admin_commission_amount',
        'instructor_commission_rate',
        'instructor_commission_amount',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'course_price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'admin_commission_rate' => 'decimal:2',
        'admin_commission_amount' => 'decimal:2',
        'instructor_commission_rate' => 'decimal:2',
        'instructor_commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
