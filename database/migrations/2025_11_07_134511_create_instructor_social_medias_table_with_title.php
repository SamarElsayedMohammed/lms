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
        // Only create if table doesn't exist
        if (!Schema::hasTable('instructor_social_medias')) {
            Schema::create('instructor_social_medias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('instructor_id')->references('id')->on('instructors')->onDelete('cascade');
                $table->string('title')->nullable();
                $table->string('url')->nullable();
                $table->unique(['instructor_id', 'title'], 'unique_instructor_social_media_title');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_social_medias');
    }
};
