<?php

declare(strict_types=1);

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
        if (!Schema::hasTable('lesson_progress')) {
            Schema::create('lesson_progress', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('lesson_id')->constrained('course_chapter_lectures')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->unsignedInteger('last_position_seconds')->default(0);
                $table->unsignedInteger('max_position_seconds')->default(0);
                $table->unsignedInteger('watched_seconds')->default(0);
                $table->decimal('percent', 5, 2)->default(0.00);
                $table->boolean('is_completed')->default(false);
                $table->json('watched_intervals')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'lesson_id'], 'uniq_user_lesson_progress');
                $table->index(['user_id', 'course_id'], 'idx_user_course_progress');
                $table->index(['course_id', 'is_completed'], 'idx_course_completed_progress');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
