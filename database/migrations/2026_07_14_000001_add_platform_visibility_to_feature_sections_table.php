<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            if (!Schema::hasColumn('feature_sections', 'show_on_web')) {
                $table->boolean('show_on_web')->default(true)->after('visibility_devices');
            }
            if (!Schema::hasColumn('feature_sections', 'show_on_mobile')) {
                $table->boolean('show_on_mobile')->default(false)->after('show_on_web');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feature_sections', function (Blueprint $table): void {
            if (Schema::hasColumn('feature_sections', 'show_on_web')) {
                $table->dropColumn('show_on_web');
            }
            if (Schema::hasColumn('feature_sections', 'show_on_mobile')) {
                $table->dropColumn('show_on_mobile');
            }
        });
    }
};
