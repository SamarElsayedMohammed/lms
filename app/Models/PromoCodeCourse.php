<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCodeCourse extends Model
{
    protected $table = 'promo_code_course'; // Important since table name is not plural

    protected $fillable = [
        'promo_code_id',
        'course_id',
    ];

    public $timestamps = false;

    // Optional relationships
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
