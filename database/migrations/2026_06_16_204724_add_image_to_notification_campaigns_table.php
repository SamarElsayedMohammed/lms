<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            // صورة/أيقونة الإشعار (اختيارية)
            $table->string('image')->nullable()->after('sent_count')
                ->comment('مسار صورة الإشعار أو الأيقونة');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
