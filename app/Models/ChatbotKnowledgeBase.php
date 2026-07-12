<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ChatbotKnowledgeBase extends Model
{
    protected $table = 'chatbot_knowledge_bases';

    protected $fillable = [
        'title',
        'content',
        'file_path',
        'file_type',
        'is_active',
        'target_audience',   // 'visitor' | 'subscriber' | 'course'
        'course_id',         // for course-specific knowledge entries
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope: only active knowledge base entries
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Relationship with course (for course-specific knowledge entries)
     */
    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Course\Course::class);
    }
}
