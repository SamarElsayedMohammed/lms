<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sliders', function (Blueprint $table): void {
            if (!Schema::hasColumn('sliders', 'title')) {
                $table->string('title')->nullable()->after('image');
            }
            if (!Schema::hasColumn('sliders', 'subtitle')) {
                $table->string('subtitle')->nullable()->after('title');
            }
            if (!Schema::hasColumn('sliders', 'cta_label')) {
                $table->string('cta_label', 100)->nullable()->after('third_party_link');
            }
            if (!Schema::hasColumn('sliders', 'cta_type')) {
                $table->string('cta_type', 50)->nullable()->default('custom_link')->after('cta_label');
            }
            if (!Schema::hasColumn('sliders', 'cta_target')) {
                $table->string('cta_target')->nullable()->after('cta_type');
            }
            if (!Schema::hasColumn('sliders', 'mobile_image')) {
                $table->string('mobile_image')->nullable()->after('image');
            }
            if (!Schema::hasColumn('sliders', 'audience')) {
                $table->string('audience', 50)->default('everyone')->after('cta_target');
            }
            if (!Schema::hasColumn('sliders', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('audience');
            }
            if (!Schema::hasColumn('sliders', 'start_at')) {
                $table->timestamp('start_at')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('sliders', 'end_at')) {
                $table->timestamp('end_at')->nullable()->after('start_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sliders', function (Blueprint $table): void {
            foreach ([
                'end_at',
                'start_at',
                'is_active',
                'audience',
                'cta_target',
                'cta_type',
                'cta_label',
                'mobile_image',
                'subtitle',
                'title',
            ] as $column) {
                if (Schema::hasColumn('sliders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
