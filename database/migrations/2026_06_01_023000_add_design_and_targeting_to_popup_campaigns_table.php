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
            // Design & Appearance
            $table->string('background_color')->nullable();
            $table->string('text_color')->nullable();
            $table->string('button_color')->nullable();
            $table->string('template_style')->nullable()->default('modal'); // modal, banner, slide-in

            // Targeting
            $table->string('target_audience')->nullable()->default('all'); // all, guests, users
            $table->string('device_type')->nullable()->default('all'); // all, desktop, mobile
            $table->json('display_pages')->nullable();
            $table->integer('delay_seconds')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('popup_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'background_color',
                'text_color',
                'button_color',
                'template_style',
                'target_audience',
                'device_type',
                'display_pages',
                'delay_seconds'
            ]);
        });
    }
};
