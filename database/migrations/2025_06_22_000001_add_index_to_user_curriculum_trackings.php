<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_curriculum_trackings', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'uct_user_status_idx');
            $table->index(['user_id', 'course_chapter_id'], 'uct_user_chapter_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_curriculum_trackings', function (Blueprint $table) {
            $table->dropIndex('uct_user_status_idx');
            $table->dropIndex('uct_user_chapter_idx');
        });
    }
};
