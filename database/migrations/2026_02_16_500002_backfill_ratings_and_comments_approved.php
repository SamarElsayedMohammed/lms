<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ratings') && Schema::hasColumn('ratings', 'status')) {
            DB::table('ratings')->whereNull('status')->orWhere('status', '')->update(['status' => 'approved']);
        }

        if (Schema::hasTable('course_discussions') && Schema::hasColumn('course_discussions', 'status')) {
            DB::table('course_discussions')->whereNull('status')->orWhere('status', '')->update(['status' => 'approved']);
        }
    }

    public function down(): void
    {
        // No reversible backfill
    }
};
