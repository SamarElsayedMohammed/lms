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
            $table->string('student_name')->nullable()->after('certificate_number');
            $table->string('arabic_title')->nullable()->after('student_name');
            $table->string('english_title')->nullable()->after('arabic_title');
            $table->string('instructor_name')->nullable()->after('english_title');

            // Add unique index to prevent duplicates
            $table->unique(['user_id', 'course_id'], 'idx_unique_user_course_cert');
        });
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
