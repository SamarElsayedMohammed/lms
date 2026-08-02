<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotVectorChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_type',
        'course_id',
        'knowledge_base_id',
        'source_type',
        'source_id',
        'title',
        'chunk_index',
        'chunk_text',
        'embedding',
        'token_count',
        'content_hash',
        'language',
        'is_active',
    ];

    protected $casts = [
        'embedding' => 'array',
        'is_active' => 'boolean',
        'chunk_index' => 'integer',
        'token_count' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(ChatbotKnowledgeBase::class, 'knowledge_base_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('bot_type', 'course')->where('course_id', $courseId);
    }

    public function scopeForAudience($query, string $botType)
    {
        return $query->where('bot_type', $botType);
    }
}
