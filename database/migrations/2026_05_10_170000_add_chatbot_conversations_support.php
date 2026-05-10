<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول المحادثات (Threads)
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // لازم يكون مسجل عشان يحفظ محادثاته
            $table->string('title')->nullable(); // عنوان المحادثة (مثلاً أول 5 كلمات من أول سؤال)
            $table->enum('type', ['general', 'course'])->default('general');
            $table->foreignId('course_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        // تحديث جدول الرسائل ليرتبط بالمحادثة
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained('chatbot_conversations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
        Schema::dropIfExists('chatbot_conversations');
    }
};
