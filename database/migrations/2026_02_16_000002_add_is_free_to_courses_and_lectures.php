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
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('approval_status');
            $table->timestamp('is_free_until')->nullable()->after('is_free');
        });

        Schema::table('course_chapter_lectures', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('free_preview');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['is_free', 'is_free_until']);
        });

        Schema::table('course_chapter_lectures', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });
    }
};
