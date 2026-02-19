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
        // Add title column to lecture_resources table
        Schema::table('lecture_resources', function (Blueprint $table) {
            $table->string('title')->nullable()->after('lecture_id');
        });

        // Add title column to quiz_resources table
        Schema::table('quiz_resources', function (Blueprint $table) {
            $table->string('title')->nullable()->after('quiz_id');
        });

        // Add title column to assignment_resources table
        Schema::table('assignment_resources', function (Blueprint $table) {
            $table->string('title')->nullable()->after('assignment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove title column from lecture_resources table
        Schema::table('lecture_resources', function (Blueprint $table) {
            $table->dropColumn('title');
        });

        // Remove title column from quiz_resources table
        Schema::table('quiz_resources', function (Blueprint $table) {
            $table->dropColumn('title');
        });

        // Remove title column from assignment_resources table
        Schema::table('assignment_resources', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
