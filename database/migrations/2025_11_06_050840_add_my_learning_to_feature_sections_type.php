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
        // Modify the enum to include 'my_learning'
        DB::statement("ALTER TABLE feature_sections MODIFY COLUMN type ENUM(
            'top_rated_courses',
            'newly_added_courses',
            'most_viewed_courses',
            'offer',
            'why_choose_us',
            'free_courses',
            'become_instructor',
            'top_rated_instructors',
            'wishlist',
            'searching_based',
            'recommend_for_you',
            'my_learning'
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to enum without my_learning
        DB::statement("ALTER TABLE feature_sections MODIFY COLUMN type ENUM(
            'top_rated_courses',
            'newly_added_courses',
            'most_viewed_courses',
            'offer',
            'why_choose_us',
            'free_courses',
            'become_instructor',
            'top_rated_instructors',
            'wishlist',
            'searching_based',
            'recommend_for_you'
        )");
    }
};

