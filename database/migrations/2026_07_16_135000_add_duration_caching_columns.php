<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_chapter_lectures', static function (Blueprint $table): void {
            if (!Schema::hasColumn('course_chapter_lectures', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->default(0)->after('seconds');
            }
        });

        Schema::table('course_chapters', static function (Blueprint $table): void {
            if (!Schema::hasColumn('course_chapters', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->default(0)->after('type');
            }
        });

        Schema::table('courses', static function (Blueprint $table): void {
            if (!Schema::hasColumn('courses', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->default(0)->after('status');
            }
            if (!Schema::hasColumn('courses', 'lectures_count')) {
                $table->unsignedInteger('lectures_count')->default(0)->after('duration_seconds');
            }
        });

        // Initialize course_chapter_lectures.duration_seconds from existing columns
        DB::statement('UPDATE course_chapter_lectures SET duration_seconds = (COALESCE(hours, 0) * 3600) + (COALESCE(minutes, 0) * 60) + COALESCE(seconds, 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_chapter_lectures', static function (Blueprint $table): void {
            if (Schema::hasColumn('course_chapter_lectures', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
        });

        Schema::table('course_chapters', static function (Blueprint $table): void {
            if (Schema::hasColumn('course_chapters', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
        });

        Schema::table('courses', static function (Blueprint $table): void {
            if (Schema::hasColumn('courses', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
            if (Schema::hasColumn('courses', 'lectures_count')) {
                $table->dropColumn('lectures_count');
            }
        });
    }
};
