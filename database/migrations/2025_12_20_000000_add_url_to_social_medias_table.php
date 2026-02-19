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
        if (Schema::hasTable('social_medias')) {
            if (!Schema::hasColumn('social_medias', 'url')) {
                Schema::table('social_medias', function (Blueprint $table) {
                    $table->string('url')->nullable()->after('icon_extension');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('social_medias')) {
            if (Schema::hasColumn('social_medias', 'url')) {
                Schema::table('social_medias', function (Blueprint $table) {
                    $table->dropColumn('url');
                });
            }
        }
    }
};

