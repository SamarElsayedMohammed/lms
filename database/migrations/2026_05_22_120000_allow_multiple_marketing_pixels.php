<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketing_pixels', function (Blueprint $table) {
            $table->dropUnique(['platform']);
            $table->string('name')->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_pixels', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unique('platform');
        });
    }
};
