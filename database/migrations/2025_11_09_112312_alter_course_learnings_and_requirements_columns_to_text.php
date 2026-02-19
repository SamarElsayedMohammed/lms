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
        // Change course_learnings.title from string to text
        Schema::table('course_learnings', function (Blueprint $table) {
            $table->text('title')->change();
        });

        // Change course_requirements.requirement from string to text
        Schema::table('course_requirements', function (Blueprint $table) {
            $table->text('requirement')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert course_learnings.title back to string
        Schema::table('course_learnings', function (Blueprint $table) {
            $table->string('title', 255)->change();
        });

        // Revert course_requirements.requirement back to string
        Schema::table('course_requirements', function (Blueprint $table) {
            $table->string('requirement', 255)->change();
        });
    }
};
