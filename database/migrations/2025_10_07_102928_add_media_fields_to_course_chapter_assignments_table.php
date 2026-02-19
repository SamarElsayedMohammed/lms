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
        Schema::table('course_chapter_assignments', function (Blueprint $table) {
            $table->string('media')->nullable()->after('instructions');
            $table->string('media_extension', 10)->nullable()->after('media');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_chapter_assignments', function (Blueprint $table) {
            $table->dropColumn(['media', 'media_extension']);
        });
    }
};
