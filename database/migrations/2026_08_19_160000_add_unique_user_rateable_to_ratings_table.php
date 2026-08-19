<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ratings')) {
            return;
        }

        $duplicates = DB::table('ratings')
            ->select('user_id', 'rateable_type', 'rateable_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id', 'rateable_type', 'rateable_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('ratings')
                ->where('user_id', $duplicate->user_id)
                ->where('rateable_type', $duplicate->rateable_type)
                ->where('rateable_id', $duplicate->rateable_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('ratings', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'rateable_type', 'rateable_id'],
                'ratings_user_rateable_unique',
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ratings')) {
            return;
        }

        Schema::table('ratings', function (Blueprint $table): void {
            $table->dropUnique('ratings_user_rateable_unique');
        });
    }
};
