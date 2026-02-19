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
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('certificate_enabled')->default(false)->after('sequential_access')->comment('Enable certificate generation for free courses');
            $table->decimal('certificate_fee', 10, 2)->nullable()->after('certificate_enabled')->comment('Certificate fee amount for free courses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['certificate_enabled', 'certificate_fee']);
        });
    }
};
