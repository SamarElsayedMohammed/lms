<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->enum('intro_video_type', ['file', 'url'])->nullable()->after('intro_video');
            $table->enum('content_structure', ['lessons', 'chapters'])->default('chapters')->after('sequential_access');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['intro_video_type', 'content_structure']);
        });
    }
};