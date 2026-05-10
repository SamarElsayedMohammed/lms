<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // اليوزر (لو مسجل)
            $table->string('session_id')->nullable()->index(); // للمستخدمين الزوار (نربط رسايلهم ببعض)
            $table->text('message'); // سؤال المستخدم
            $table->text('reply'); // رد الـ AI أو الـ FAQ
            $table->enum('type', ['faq', 'ai_general', 'ai_course'])->default('ai_general');
            $table->foreignId('course_id')->nullable()->constrained()->onDelete('set null'); // لو السؤال كان جوه كورس
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
