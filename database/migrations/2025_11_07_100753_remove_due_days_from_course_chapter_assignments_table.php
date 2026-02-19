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
        if (Schema::hasTable('course_chapter_assignments')) {
            Schema::table('course_chapter_assignments', function (Blueprint $table) {
                if (Schema::hasColumn('course_chapter_assignments', 'due_days')) {
                    $table->dropColumn('due_days');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('course_chapter_assignments')) {
            Schema::table('course_chapter_assignments', function (Blueprint $table) {
                if (!Schema::hasColumn('course_chapter_assignments', 'due_days')) {
                    $table->integer('due_days')->nullable()->after('instructions');
                }
            });
        }
    }
};
