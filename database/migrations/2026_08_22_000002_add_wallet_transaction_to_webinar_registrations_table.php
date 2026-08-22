<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('paid_amount')->index();
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->dropIndex(['wallet_transaction_id']);
            $table->dropColumn('wallet_transaction_id');
        });
    }
};
