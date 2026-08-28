<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            if (!Schema::hasColumn('feature_sections', 'subtitle')) {
                $table->string('subtitle')->nullable()->after('title');
            }
            if (!Schema::hasColumn('feature_sections', 'audience')) {
                $table->string('audience', 50)->default('everyone')->after('visibility_devices');
            }
            if (!Schema::hasColumn('feature_sections', 'config')) {
                $table->json('config')->nullable()->after('audience');
            }
            if (!Schema::hasColumn('feature_sections', 'mobile_row_order')) {
                $table->integer('mobile_row_order')->nullable()->after('row_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            foreach (['mobile_row_order', 'config', 'audience', 'subtitle'] as $column) {
                if (Schema::hasColumn('feature_sections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
