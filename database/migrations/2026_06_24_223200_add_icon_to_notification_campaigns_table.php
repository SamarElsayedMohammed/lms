<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->after('image');
            $table->string('icon_color', 16)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_color']);
        });
    }
};
