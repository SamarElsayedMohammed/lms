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
        /***********************************************************/

        Schema::create('course_chapters',function(Blueprint $table){
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(0);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        /***********************************************************/

        // Course Chapter Lectures
        Schema::create('course_chapter_lectures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('type', ['file', 'url', 'youtube_url']);
            $table->string('file')->nullable();
            $table->string('file_extension')->nullable();
            $table->text('url')->nullable();
            $table->text('youtube_url')->nullable();
            $table->integer('hours')->nullable();
            $table->integer('minutes')->nullable();
            $table->integer('seconds')->nullable();
            $table->text('description')->nullable();
            $table->integer('chapter_order')->comment('Order relative to all content types in chapter');
            $table->boolean('is_active')->default(1);
            $table->boolean('free_preview')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        // Lecture Resources
        Schema::create('lecture_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('lecture_id')->references('id')->on('course_chapter_lectures')->onDelete('cascade');
            $table->enum('type', ['file', 'url']);
            $table->string('file')->nullable();
            $table->string('file_extension')->nullable();
            $table->text('url')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Lecture Video User Tracks
        Schema::create('lecture_user_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('lecture_id')->references('id')->on('course_chapter_lectures')->onDelete('cascade');
            $table->integer('total_duration')->nullable()->comment('in seconds, total duration of the video');
            $table->integer('duration')->nullable()->comment('in seconds, progress');
            $table->boolean('is_completed')->nullable();
            $table->unique(['user_id', 'lecture_id'], 'unique_user_lecture_track');
            $table->timestamps();
            $table->softDeletes();
        });

        /***********************************************************/

        // Course Chapter Lecture Resources
        Schema::create('course_chapter_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('type', ['file', 'url']);
            $table->string('file')->nullable();
            $table->string('file_extension')->nullable();
            $table->text('url')->nullable();
            $table->text('description')->nullable();
            $table->integer('duration')->nullable()->comment('in seconds, progress');
            $table->integer('chapter_order')->comment('Order relative to all content types in chapter');
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        /***********************************************************/

        // Course Chapter Lecture Quizzes
        Schema::create('course_chapter_quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('time_limit')->nullable()->comment('in seconds');
            $table->integer('total_points')->nullable();
            $table->integer('passing_score')->nullable()->comment('in percentage');
            $table->integer('chapter_order')->comment('Order relative to all content types in chapter');
            $table->boolean('can_skip')->default(0);
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });


        // Course Chapter Lecture Quiz Questions
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_quiz_id')->references('id')->on('course_chapter_quizzes')->onDelete('cascade');
            $table->text('question');
            $table->decimal('points', 10, 2)->default(0);
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Chapter Lecture Quiz Options
        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('quiz_question_id')->references('id')->on('quiz_questions')->onDelete('cascade');
            $table->string('option');
            $table->boolean('is_correct')->default(0);
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Lecture User Quiz Attempts
        Schema::create('user_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_quiz_id')->references('id')->on('course_chapter_quizzes')->onDelete('cascade');
            $table->integer('total_time')->nullable()->comment('in seconds');
            $table->integer('time_taken')->nullable()->comment('in seconds');
            $table->decimal('score', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Lecture Quiz User Answers
        Schema::create('user_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('quiz_question_id')->references('id')->on('quiz_questions')->onDelete('cascade');
            $table->foreignId('quiz_option_id')->references('id')->on('quiz_options')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });

        // Quiz Resources
        Schema::create('quiz_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('quiz_id')->references('id')->on('course_chapter_quizzes')->onDelete('cascade');
            $table->enum('type', ['file', 'url']);
            $table->string('file')->nullable();
            $table->string('file_extension')->nullable();
            $table->text('url')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        /***********************************************************/

        // Course Chapter Lecture Assignments
        Schema::create('course_chapter_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->integer('due_days')->nullable();
            $table->integer('max_file_size')->nullable()->comment('in MB');
            $table->string('allowed_file_types')->nullable();
            $table->decimal('points', 10, 2)->default(0);
            $table->integer('chapter_order')->comment('Order relative to all content types in chapter');
            $table->boolean('can_skip')->default(0);
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Lecture Assignment Submissions
        Schema::create('user_assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_assignment_id')->references('id')->on('course_chapter_assignments')->onDelete('cascade');
            $table->enum('status', ['pending', 'submitted', 'accepted', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->decimal('points', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });


        // Assignment Resources
        Schema::create('assignment_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('assignment_id')->references('id')->on('course_chapter_assignments')->onDelete('cascade');
            $table->enum('type', ['file', 'url']);
            $table->string('file')->nullable();
            $table->string('file_extension')->nullable();
            $table->text('url')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        
        // Lecture Assignment Submission Files
        Schema::create('user_assignment_files', function (Blueprint $table) {
           $table->id();
           $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
           $table->foreignId('user_assignment_submission_id')->references('id')->on('user_assignment_submissions')->onDelete('cascade');
           $table->enum('type', ['file', 'url']);
           $table->string('file')->nullable();
           $table->string('file_extension')->nullable();
           $table->text('url')->nullable();
           $table->timestamps();
           $table->softDeletes();
       });
        /***********************************************************/


        // User Course Chapter Track
        Schema::create('user_course_chapter_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_chapter_id')->references('id')->on('course_chapters')->onDelete('cascade');
            $table->enum('status', ['started', 'in_progress', 'completed'])->default('started');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // User Course Track
        Schema::create('user_course_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->enum('status', ['started', 'in_progress', 'completed'])->default('started');
            $table->unique(['user_id', 'course_id'], 'unique_user_course_track');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        /***********************************************************/

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('course_chapter_lectures');
        Schema::dropIfExists('lecture_resources');
        Schema::dropIfExists('lecture_user_tracks');
        Schema::dropIfExists('course_chapter_resources');
        Schema::dropIfExists('course_chapter_quizzes');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('user_quiz_attempts');
        Schema::dropIfExists('user_quiz_answers');
        Schema::dropIfExists('quiz_resources');
        Schema::dropIfExists('course_chapter_assignments');
        Schema::dropIfExists('user_assignment_submissions');
        Schema::dropIfExists('assignment_resources');
        Schema::dropIfExists('user_assignment_files');
        Schema::dropIfExists('user_course_tracks');
        Schema::dropIfExists('user_course_chapter_tracks');
        Schema::enableForeignKeyConstraints();
    }
};
