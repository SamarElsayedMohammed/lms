<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'course_id',
        'promo_code_id',
    ];

    // Relationship: Cart belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: Cart belongs to a course
    public function course()
    {
        return $this->belongsTo(Course\Course::class);
    }

    // Relationship: Cart belongs to a promo code
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }
}
