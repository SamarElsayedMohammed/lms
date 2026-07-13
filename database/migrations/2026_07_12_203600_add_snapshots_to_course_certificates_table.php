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
            $table->string('student_name', 255)->nullable()->after('certificate_number');
            $table->string('arabic_title', 500)->nullable()->after('student_name');
            $table->string('english_title', 500)->nullable()->after('arabic_title');
            $table->string('instructor_name', 255)->nullable()->after('english_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->dropColumn([
                'student_name',
                'arabic_title',
                'english_title',
                'instructor_name'
            ]);
        });
    }
};
