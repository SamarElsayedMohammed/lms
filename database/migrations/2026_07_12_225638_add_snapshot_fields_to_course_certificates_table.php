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
            if (!Schema::hasColumn('course_certificates', 'student_name')) {
                $table->string('student_name')->nullable()->after('certificate_number');
            }
            if (!Schema::hasColumn('course_certificates', 'arabic_title')) {
                $table->string('arabic_title')->nullable()->after('student_name');
            }
            if (!Schema::hasColumn('course_certificates', 'english_title')) {
                $table->string('english_title')->nullable()->after('arabic_title');
            }
            if (!Schema::hasColumn('course_certificates', 'instructor_name')) {
                $table->string('instructor_name')->nullable()->after('english_title');
            }
        });

        try {
            Schema::table('course_certificates', function (Blueprint $table) {
                $table->unique(['user_id', 'course_id'], 'idx_unique_user_course_cert');
            });
        } catch (\Exception $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->dropUnique('idx_unique_user_course_cert');
            $table->dropColumn([
                'student_name',
                'arabic_title',
                'english_title',
                'instructor_name',
            ]);
        });
    }
};
