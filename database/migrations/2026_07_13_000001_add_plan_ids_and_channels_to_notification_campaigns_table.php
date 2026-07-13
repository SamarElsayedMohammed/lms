<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            // Replaces single plan_id with an array of plan IDs for multi-plan targeting
            $table->json('plan_ids')->nullable()->after('plan_id');
            // Which channels were used when this campaign was dispatched: ['web','mail']
            $table->json('channels')->nullable()->after('plan_ids');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn(['plan_ids', 'channels']);
        });
    }
};
