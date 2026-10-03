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
        if (!Schema::hasTable('lecture_watch_segments')) {
            Schema::create('lecture_watch_segments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('lecture_id')
                    ->constrained('course_chapter_lectures')
                    ->cascadeOnDelete();

                $table->unsignedInteger('start_second');
                $table->unsignedInteger('end_second');

                $table->timestamps();

                $table->index(['user_id', 'lecture_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lecture_watch_segments');
    }
};
