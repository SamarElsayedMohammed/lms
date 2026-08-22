<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_reads', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('read_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_reads', function (Blueprint $table): void {
            $table->dropColumn('hidden_at');
        });
    }
};
