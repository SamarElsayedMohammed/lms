<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_vector_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('bot_type', 30)->default('course'); // visitor, subscriber, course
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('knowledge_base_id')->nullable();
            $table->string('source_type', 50)->default('file'); // file, text, lesson, transcript, faq
            $table->string('source_id', 100)->nullable();
            $table->string('title', 255)->nullable();
            $table->integer('chunk_index')->default(0);
            $table->longText('chunk_text');
            $table->json('embedding')->nullable(); // float array
            $table->integer('token_count')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->string('language', 10)->default('ar');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['bot_type', 'course_id', 'is_active']);
            $table->index('knowledge_base_id');
            $table->index('content_hash');

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('knowledge_base_id')->references('id')->on('chatbot_knowledge_bases')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_vector_chunks');
    }
};
