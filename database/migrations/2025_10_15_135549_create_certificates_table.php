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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['course_completion', 'exam_completion', 'custom']);
            $table->string('background_image')->nullable();
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->string('signature_image')->nullable();
            $table->string('signature_text')->nullable();
            $table->json('template_settings')->nullable(); // For custom positioning, fonts, colors
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
