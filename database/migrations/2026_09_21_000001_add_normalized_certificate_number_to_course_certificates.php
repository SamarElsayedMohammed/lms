<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_certificates', function (Blueprint $table): void {
            if (!Schema::hasColumn('course_certificates', 'certificate_number_normalized')) {
                $table->string('certificate_number_normalized', 191)
                    ->nullable()
                    ->after('certificate_number');
                $table->index('certificate_number_normalized', 'course_certificates_cert_num_norm_idx');
            }
        });

        // Safe backfill for existing records
        try {
            DB::table('course_certificates')
                ->whereNotNull('certificate_number')
                ->whereNull('certificate_number_normalized')
                ->orderBy('id')
                ->chunk(100, function ($certificates): void {
                    foreach ($certificates as $cert) {
                        $normalized = strtoupper(preg_replace('/[\s\-\_]+/u', '', (string) $cert->certificate_number));
                        DB::table('course_certificates')
                            ->where('id', $cert->id)
                            ->update(['certificate_number_normalized' => $normalized]);
                    }
                });
        } catch (\Throwable) {
            // Safe ignore during fresh testing migrations
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table): void {
            if (Schema::hasColumn('course_certificates', 'certificate_number_normalized')) {
                try {
                    $table->dropIndex('course_certificates_cert_num_norm_idx');
                } catch (\Throwable) {}
                $table->dropColumn('certificate_number_normalized');
            }
        });
    }
};