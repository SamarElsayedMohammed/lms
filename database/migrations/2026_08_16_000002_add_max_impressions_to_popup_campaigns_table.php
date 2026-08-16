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
        Schema::table('popup_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('popup_campaigns', 'max_impressions')) {
                $table->unsignedInteger('max_impressions')->nullable()->after('delay_seconds')
                      ->comment('Maximum lifetime impressions allowed per client browser (null = unlimited)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('popup_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('popup_campaigns', 'max_impressions')) {
                $table->dropColumn('max_impressions');
            }
        });
    }
};
