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
        // Tags
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('tag');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Languages
        Schema::create('course_languages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Courses
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('intro_video')->nullable();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->enum('level',['beginner','intermediate','advanced'])->comment('beginner,intermediate,advanced');
            $table->enum('course_type',['free','paid'])->comment('free,paid');
            $table->decimal('price',10,2)->nullable();
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->foreignId('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->boolean('is_active')->default(0);
            $table->foreignId('language_id')->references('id')->on('course_languages')->onDelete('cascade');
            $table->string('meta_title')->nullable();
            $table->string('meta_image')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Instructors
        Schema::create('course_instructors',function(Blueprint $table){
            $table->id();
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Learnings
        Schema::create('course_learnings',function(Blueprint $table){
            $table->id();
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Requirements
        Schema::create('course_requirements',function(Blueprint $table){
            $table->id();
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->string('requirement');
            $table->timestamps();
            $table->softDeletes();
        });

        // Course Tags
        Schema::create('course_tags',function(Blueprint $table){
            $table->id();
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreignId('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('tags');
        Schema::dropIfExists('course_languages');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_instructors');
        Schema::dropIfExists('course_learnings');
        Schema::dropIfExists('course_requirements');
        Schema::dropIfExists('course_tags');
        Schema::enableForeignKeyConstraints();
    }
};
