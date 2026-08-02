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
        'target_audience',
        'course_id',
        'processing_status',
        'chunk_count',
        'indexed_at',
        'failed_at',
        'failure_reason',
        'content_hash',
        'language',
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

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Course\Course::class);
    }
}
