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
            if (!Schema::hasColumn('video_progress', 'session_id')) {
                $table->string('session_id')->nullable()->after('completed_segments');
                $table->string('device')->nullable()->after('session_id');
                $table->string('browser')->nullable()->after('device');
                $table->string('ip')->nullable()->after('browser');
                $table->unsignedInteger('watch_count')->default(1)->after('ip');
                $table->string('progress_state')->default('playing')->after('watch_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $columns = ['session_id', 'device', 'browser', 'ip', 'watch_count', 'progress_state'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('video_progress', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
