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
        Schema::table('course_certificates', function (Blueprint $table) {
            if (!Schema::hasColumn('course_certificates', 'issuance_source')) {
                $table->string('issuance_source', 50)->default('automatic')->after('status');
            }
            if (!Schema::hasColumn('course_certificates', 'revoked_by')) {
                $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            if (Schema::hasColumn('course_certificates', 'issuance_source')) {
                $table->dropColumn('issuance_source');
            }
            if (Schema::hasColumn('course_certificates', 'revoked_by')) {
                $table->dropColumn('revoked_by');
            }
        });
    }
};
