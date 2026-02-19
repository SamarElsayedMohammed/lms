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
        Schema::create('lecture_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecture_id')->constrained('course_chapter_lectures')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedInteger('file_size')->default(0);
            $table->string('file_type', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('lecture_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lecture_attachments');
    }
};
