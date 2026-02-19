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
        // First, remove any duplicate entries (keep only the first one of each type)
        $duplicates = DB::table('certificates')
            ->select('type', DB::raw('MIN(id) as keep_id'))
            ->groupBy('type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('certificates')
                ->where('type', $duplicate->type)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        // Add unique constraint on type column
        Schema::table('certificates', function (Blueprint $table) {
            $table->unique('type', 'certificates_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_type_unique');
        });
    }
};
