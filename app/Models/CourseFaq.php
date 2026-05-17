<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseFaq extends Model
{
    protected $fillable = [
        'course_id',
        'question',
        'answer',
        'sequence',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sequence'  => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence')->orderBy('id');
    }
}
