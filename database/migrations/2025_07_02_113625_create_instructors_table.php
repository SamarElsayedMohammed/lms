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
        // Instructors Table
        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->enum('type', ['individual', 'team']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->string('reason')->comment('Reason for rejected or suspended')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Presonal Details of Instructor
        Schema::create('instructor_personal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->references('id')->on('instructors')->onDelete('cascade');
            $table->text('qualification')->nullable();
            $table->integer('years_of_experience')->nullable();
            $table->text('skills')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('team_name')->nullable();
            $table->string('team_logo')->nullable();
            $table->string('team_logo_extension')->nullable();
            $table->text('about_me')->nullable();
            $table->string('id_proof')->nullable();
            $table->string('id_proof_extension')->nullable();
            $table->string('preview_video')->nullable();
            $table->string('preview_video_extension')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Social Media
        Schema::create('social_medias', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('icon')->nullable();
            $table->string('icon_extension')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Instructor Social Media
        Schema::create('instructor_social_medias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->references('id')->on('instructors')->onDelete('cascade');
            $table->foreignId('social_media_id')->references('id')->on('social_medias')->onDelete('cascade');
            $table->string('url')->nullable();
            $table->unique(['instructor_id', 'social_media_id'], 'unique_instructor_social_media');
            $table->timestamps();
            $table->softDeletes();
        });

        // Drop User Form Fields Values
        Schema::dropIfExists('user_form_fields_values');
        // Instructor Other Details
        Schema::create('instructor_other_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->references('id')->on('instructors')->onDelete('cascade');
            $table->foreignId('custom_form_field_id')->references('id')->on('custom_form_fields')->onDelete('cascade');            
            $table->foreignId('custom_form_field_option_id')->nullable()->references('id')->on('custom_form_field_options')->onDelete('cascade');            
            $table->string('value')->nullable();
            $table->string('extension')->nullable();
            $table->unique(['instructor_id', 'custom_form_field_id'], 'unique_instructor_other_details');
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
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('instructor_personal_details');
        Schema::dropIfExists('social_medias');
        Schema::dropIfExists('instructor_social_medias');
        Schema::dropIfExists('instructor_other_details');
        Schema::enableForeignKeyConstraints();
    }
};
