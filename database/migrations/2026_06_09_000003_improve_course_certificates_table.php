<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Improve the course_certificates table:
 *
 * 1. Add `status` column (active | revoked) — defaults to 'active'.
 * 2. Add `unique(user_id, course_id)` constraint to prevent duplicates
 *    at the DB level (application already uses firstOrCreate but DB constraint
 *    is the safety net).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            // Status field for revoke support
            $table->enum('status', ['active', 'revoked'])
                ->default('active')
                ->after('issued_date');

            // Revocation metadata
            $table->timestamp('revoked_at')->nullable()->after('status');
            $table->string('revoked_reason')->nullable()->after('revoked_at');
        });

        // Remove any existing duplicate (user_id, course_id) pairs before adding unique index.
        // Keep the oldest record in each group.
        $duplicates = DB::table('course_certificates')
            ->select('user_id', 'course_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'course_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('course_certificates')
                ->where('user_id', $dup->user_id)
                ->where('course_id', $dup->course_id)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        Schema::table('course_certificates', function (Blueprint $table) {
            $table->unique(['user_id', 'course_id'], 'course_certificates_user_course_unique');
        });
    }

    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->dropUnique('course_certificates_user_course_unique');
            $table->dropColumn(['status', 'revoked_at', 'revoked_reason']);
        });
    }
};
