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
        Schema::table('user_course_tracks', function (Blueprint $table) {
            // Modify the status column to ensure proper ENUM values
            $table->enum('status', ['started', 'in_progress', 'completed'])->default('started')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_course_tracks', function (Blueprint $table) {
            // Revert back to original if needed
            $table->enum('status', ['started', 'in_progress', 'completed'])->default('started')->change();
        });
    }
};
