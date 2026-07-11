<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            if (!Schema::hasColumn('feature_sections', 'layout')) {
                $table->string('layout')->nullable()->default('carousel')->after('is_active');
            }
            if (!Schema::hasColumn('feature_sections', 'grid_columns')) {
                $table->unsignedTinyInteger('grid_columns')->nullable()->default(4)->after('layout');
            }
            if (!Schema::hasColumn('feature_sections', 'background')) {
                $table->string('background')->nullable()->default('white')->after('grid_columns');
            }
            if (!Schema::hasColumn('feature_sections', 'sorting')) {
                $table->string('sorting')->nullable()->default('newest')->after('background');
            }
            if (!Schema::hasColumn('feature_sections', 'responsive_limits')) {
                $table->json('responsive_limits')->nullable()->after('sorting');
            }
            if (!Schema::hasColumn('feature_sections', 'visibility_permissions')) {
                $table->json('visibility_permissions')->nullable()->after('responsive_limits');
            }
            if (!Schema::hasColumn('feature_sections', 'visibility_devices')) {
                $table->json('visibility_devices')->nullable()->after('visibility_permissions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            foreach ([
                'visibility_devices',
                'visibility_permissions',
                'responsive_limits',
                'sorting',
                'background',
                'grid_columns',
                'layout',
            ] as $column) {
                if (Schema::hasColumn('feature_sections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};