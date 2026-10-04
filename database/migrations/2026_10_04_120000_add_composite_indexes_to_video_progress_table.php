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
        Schema::table('video_progress', function (Blueprint $table) {
            $table->index(['user_id', 'is_completed'], 'idx_user_progress_completed');
            $table->index(['lecture_id', 'is_completed'], 'idx_lecture_progress_completed');
            $table->index(['user_id', 'lecture_id', 'is_completed'], 'idx_user_lecture_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $table->dropIndex('idx_user_progress_completed');
            $table->dropIndex('idx_lecture_progress_completed');
            $table->dropIndex('idx_user_lecture_completed');
        });
    }
};
