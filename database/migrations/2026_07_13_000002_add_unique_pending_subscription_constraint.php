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
        try {
            // Keep the latest pending subscription for each user and cancel older duplicates
            $duplicates = DB::table('subscriptions')
                ->select('user_id', DB::raw('MAX(id) as keep_id'))
                ->whereIn('status', ['pending', 'pending_approval'])
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                DB::table('subscriptions')
                    ->where('user_id', $dup->user_id)
                    ->whereIn('status', ['pending', 'pending_approval'])
                    ->where('id', '!=', $dup->keep_id)
                    ->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'Auto-cancelled duplicate pending subscription',
                        'cancelled_at' => now(),
                    ]);
            }
        } catch (\Exception $e) {
            // Log or ignore if table/columns don't exist yet in some environments
        }

        try {
            // Functional index is available in MySQL 8.0.13+ and PostgreSQL
            DB::statement("CREATE UNIQUE INDEX idx_unique_pending_sub ON subscriptions (user_id, (CASE WHEN status IN ('pending', 'pending_approval') THEN 1 ELSE NULL END))");
        } catch (\Exception $e) {
            // Index might already exist or DB might not support functional indexes
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_unique_pending_sub');
        });
    }
};
