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
        if (Schema::hasTable('payment_methods') && !Schema::hasColumn('payment_methods', 'deleted_at')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'deleted_at')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
