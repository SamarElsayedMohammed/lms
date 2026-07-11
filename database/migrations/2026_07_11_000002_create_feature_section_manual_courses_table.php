<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_section_manual_courses')) {
            return;
        }

        Schema::create('feature_section_manual_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_section_id')->constrained('feature_sections')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['feature_section_id', 'course_id'], 'feature_section_manual_courses_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_section_manual_courses');
    }
};