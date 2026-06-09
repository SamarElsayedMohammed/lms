<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-course chatbot settings to the courses table.
 *
 * Each course can now have its own:
 * - chatbot_enabled  : whether the AI assistant is active for this course
 * - chatbot_name     : custom bot name (e.g. "Python AI Tutor")
 * - chatbot_welcome  : welcome message shown when user opens the chat
 * - chatbot_system_prompt : extra system instructions for this course's bot
 * - chatbot_max_tokens    : response length limit (overrides global setting)
 *
 * These are separate from the site-wide visitor chatbot settings stored
 * in the `settings` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Toggle — independent of global chatbot_enabled
            $table->boolean('chatbot_enabled')
                ->default(false)
                ->after('ai_knowledge_content')
                ->comment('Enable/disable course chatbot independently of global chatbot');

            // Custom display name for this course's bot
            $table->string('chatbot_name', 100)
                ->nullable()
                ->after('chatbot_enabled')
                ->comment('e.g. "Python AI Tutor" — falls back to global chatbot_name');

            // Welcome message shown on chat open
            $table->string('chatbot_welcome_message', 500)
                ->nullable()
                ->after('chatbot_name')
                ->comment('First message the bot sends — falls back to global setting');

            // Extra instructions appended to the system prompt
            $table->text('chatbot_system_prompt')
                ->nullable()
                ->after('chatbot_welcome_message')
                ->comment('Extra admin-defined instructions for this course\'s AI');

            // Override global max_tokens limit
            $table->unsignedSmallInteger('chatbot_max_tokens')
                ->nullable()
                ->after('chatbot_system_prompt')
                ->comment('Max tokens for AI response — null means use global setting');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'chatbot_enabled',
                'chatbot_name',
                'chatbot_welcome_message',
                'chatbot_system_prompt',
                'chatbot_max_tokens',
            ]);
        });
    }
};
