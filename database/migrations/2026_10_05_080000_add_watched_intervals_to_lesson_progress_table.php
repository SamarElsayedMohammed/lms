<?php

declare(strict_types=1);

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
        if (Schema::hasTable('lesson_progress') && !Schema::hasColumn('lesson_progress', 'watched_intervals')) {
            Schema::table('lesson_progress', function (Blueprint $table) {
                $table->json('watched_intervals')->nullable()->after('is_completed');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('lesson_progress') && Schema::hasColumn('lesson_progress', 'watched_intervals')) {
            Schema::table('lesson_progress', function (Blueprint $table) {
                $table->dropColumn('watched_intervals');
            });
        }
    }
};
