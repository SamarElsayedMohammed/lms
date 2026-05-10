<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Course\Course;

class ChatbotConversation extends Model
{
    protected $table = 'chatbot_conversations';

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'course_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * Relationship with user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with course
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relationship with messages
     */
    public function messages()
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id')->orderBy('created_at', 'asc');
    }
}
