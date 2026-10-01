<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ChatbotKnowledgeBase extends Model
{
    protected $table = 'chatbot_knowledge_bases';

    protected $fillable = [
        'title',
        'content',
        'file_path',
        'file_type',
        'source_url',
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

    protected static function booted(): void
    {
        static::saving(static function (self $entry): void {
            if (! self::sourceUrlColumnExists() && array_key_exists('source_url', $entry->getAttributes())) {
                unset($entry->source_url);
            }
        });
    }

    private static ?bool $sourceUrlColumn = null;

    private static function sourceUrlColumnExists(): bool
    {
        if (self::$sourceUrlColumn === null) {
            self::$sourceUrlColumn = Schema::hasColumn((new self)->getTable(), 'source_url');
        }

        return self::$sourceUrlColumn;
    }

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
