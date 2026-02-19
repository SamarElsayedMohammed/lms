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
        Schema::create('user_curriculum_trackings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_chapter_id');
            $table->unsignedBigInteger('model_id'); // ID of the specific item (lecture, quiz, etc.)
            $table->string('model_type'); // Type: lecture, quiz, assignment, resource
            $table->string('status')->default('in_progress'); // in_progress, completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('time_spent')->nullable(); // Time spent in seconds
            $table->json('metadata')->nullable(); // Additional data like quiz score, assignment submission, etc.
            $table->softDeletes(); // Add soft deletes support
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            
            // Indexes for better performance
            $table->index(['user_id', 'course_chapter_id']);
            $table->index(['model_id', 'model_type']);
            $table->unique(['user_id', 'course_chapter_id', 'model_id', 'model_type'], 'unique_user_curriculum_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_curriculum_trackings');
    }
};
