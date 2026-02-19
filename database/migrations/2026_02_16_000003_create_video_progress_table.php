<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecture_id')->constrained('course_chapter_lectures')->cascadeOnDelete();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('last_position')->default(0);
            $table->decimal('watch_percentage', 5, 2)->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lecture_id']);
            $table->index('user_id');
            $table->index('lecture_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_progress');
    }
};
