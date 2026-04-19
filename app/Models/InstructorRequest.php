<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstructorRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'specialty',
        'experience_bio',
        'status',
    ];

    /**
     * Get the status options
     */
    public static function getStatusOptions()
    {
        return [
            'pending' => 'Pending',
            'contacted' => 'Contacted',
            'ignored' => 'Ignored',
        ];
    }

    /**
     * Get the status label attribute
     */
    public function getStatusLabelAttribute()
    {
        $statuses = self::getStatusOptions();
        return $statuses[$this->status] ?? 'Unknown';
    }
}
