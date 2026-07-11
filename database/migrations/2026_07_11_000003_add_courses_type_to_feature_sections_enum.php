<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES_WITH_COURSES = "'top_rated_courses','newly_added_courses','offer','why_choose_us','free_courses','become_instructor','top_rated_instructors','wishlist','searching_based','recommend_for_you','most_viewed_courses','my_learning','courses'";
    private const TYPES_WITHOUT_COURSES = "'top_rated_courses','newly_added_courses','offer','why_choose_us','free_courses','become_instructor','top_rated_instructors','wishlist','searching_based','recommend_for_you','most_viewed_courses','my_learning'";

    public function up(): void
    {
        DB::statement('ALTER TABLE feature_sections MODIFY COLUMN type ENUM(' . self::TYPES_WITH_COURSES . ') NOT NULL');
    }

    public function down(): void
    {
        DB::table('feature_sections')->where('type', 'courses')->update(['type' => 'newly_added_courses']);
        DB::statement('ALTER TABLE feature_sections MODIFY COLUMN type ENUM(' . self::TYPES_WITHOUT_COURSES . ') NOT NULL');
    }
};