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
        Schema::create('feature_sections', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'top_rated_courses',
                'newly_added_courses',
                'offer',
                'why_choose_us',
                'free_courses',
                'become_instructor',
                'top_rated_instructors',
                'wishlist',
                'searching_based',
                'recommend_for_you'
            ]);
            $table->string('title')->nullable();
            $table->unsignedInteger('limit')->nullable();
            $table->unsignedTinyInteger('row_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_sections');
    }
};
