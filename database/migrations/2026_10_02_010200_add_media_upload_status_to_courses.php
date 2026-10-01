<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'media_upload_status')) {
                $table->string('media_upload_status', 32)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'media_upload_status')) {
                $table->dropColumn('media_upload_status');
            }
        });
    }
};
