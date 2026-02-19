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
        // First, update all 'processed' records to 'approved'
        DB::table('refund_requests')
            ->where('status', 'processed')
            ->update(['status' => 'approved']);

        // Modify the enum to remove 'processed' and keep only 'pending', 'approved', 'rejected'
        // Using raw SQL because Laravel doesn't support modifying enum values directly
        DB::statement("ALTER TABLE refund_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore 'processed' to the enum
        DB::statement("ALTER TABLE refund_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'processed') NOT NULL DEFAULT 'pending'");
    }
};
