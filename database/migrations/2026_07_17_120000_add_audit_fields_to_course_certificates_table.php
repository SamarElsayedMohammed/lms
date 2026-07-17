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
            if (!Schema::hasColumn('course_certificates', 'verification_code')) {
                $table->string('verification_code')->nullable()->unique()->after('certificate_number');
            }
            if (!Schema::hasColumn('course_certificates', 'verification_token')) {
                $table->string('verification_token')->nullable()->unique()->after('verification_code');
            }
            if (!Schema::hasColumn('course_certificates', 'enrollment_id')) {
                $table->unsignedBigInteger('enrollment_id')->nullable()->after('course_id');
            }
            if (!Schema::hasColumn('course_certificates', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('issued_date');
            }
            if (!Schema::hasColumn('course_certificates', 'certificate_template_id')) {
                $table->unsignedBigInteger('certificate_template_id')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('course_certificates', 'verification_url')) {
                $table->string('verification_url')->nullable()->after('certificate_template_id');
            }
            if (!Schema::hasColumn('course_certificates', 'qr_code_path')) {
                $table->string('qr_code_path')->nullable()->after('verification_url');
            }
            if (!Schema::hasColumn('course_certificates', 'pdf_path')) {
                $table->string('pdf_path')->nullable()->after('qr_code_path');
            }
            if (!Schema::hasColumn('course_certificates', 'issuer_id')) {
                $table->unsignedBigInteger('issuer_id')->nullable()->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->dropColumn([
                'verification_code',
                'verification_token',
                'enrollment_id',
                'completed_at',
                'certificate_template_id',
                'verification_url',
                'qr_code_path',
                'pdf_path',
                'issuer_id'
            ]);
        });
    }
};
