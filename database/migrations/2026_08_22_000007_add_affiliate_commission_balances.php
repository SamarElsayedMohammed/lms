<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table): void {
            $table->decimal('remaining_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('transferred_amount', 10, 2)->default(0)->after('remaining_amount');
        });
        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->json('commission_allocations')->nullable()->after('commission_ids');
        });

        DB::table('affiliate_commissions')
            ->whereIn('status', ['pending', 'available'])
            ->update(['remaining_amount' => DB::raw('amount')]);
        DB::table('affiliate_commissions')
            ->whereNotIn('status', ['pending', 'available'])
            ->update(['remaining_amount' => 0]);

        // SQLite stores enum columns as text and does not support MySQL's
        // `ALTER TABLE ... MODIFY COLUMN` syntax. The existing text column
        // already accepts the additional status, so only MySQL needs the enum
        // definition change.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `affiliate_commissions` MODIFY COLUMN `status` ENUM('pending', 'available', 'withdrawn', 'transferred_to_wallet', 'cancelled') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        DB::table('affiliate_commissions')
            ->where('status', 'transferred_to_wallet')
            ->update(['status' => 'withdrawn']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `affiliate_commissions` MODIFY COLUMN `status` ENUM('pending', 'available', 'withdrawn', 'cancelled') DEFAULT 'pending'");
        }

        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->dropColumn('commission_allocations');
        });
        Schema::table('affiliate_commissions', function (Blueprint $table): void {
            $table->dropColumn(['remaining_amount', 'transferred_amount']);
        });
    }
};
