<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $table->json('watched_segments')->nullable()->after('watch_percentage');
            $table->unsignedInteger('segment_size')->default(5)->after('watched_segments');
            $table->unsignedInteger('total_segments')->default(0)->after('segment_size');
            $table->unsignedInteger('completed_segments')->default(0)->after('total_segments');
        });
    }

    public function down(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $table->dropColumn(['watched_segments', 'segment_size', 'total_segments', 'completed_segments']);
        });
    }
};
