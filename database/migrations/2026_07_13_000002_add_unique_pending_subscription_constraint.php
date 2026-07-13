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
        // Functional index is available in MySQL 8.0.13+ and PostgreSQL
        DB::statement("CREATE UNIQUE INDEX idx_unique_pending_sub ON subscriptions (user_id, (CASE WHEN status IN ('pending', 'pending_approval') THEN 1 ELSE NULL END))");
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
