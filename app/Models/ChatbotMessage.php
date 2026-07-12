<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Course\Course;
use App\Models\ChatbotConversation;

class ChatbotMessage extends Model
{
    protected $table = 'chatbot_messages';

    protected $fillable = [
        'user_id',
        'conversation_id',   // Required so messages are linked to their conversation
        'session_id',
        'message',
        'reply',
        'type',
        'course_id',
    ];

    /**
     * Relationship with user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with conversation
     */
    public function conversation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class);
    }

    /**
     * Relationship with course
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
