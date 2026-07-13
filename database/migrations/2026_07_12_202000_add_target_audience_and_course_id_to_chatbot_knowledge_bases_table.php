<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'target_audience')) {
                $table->string('target_audience')->default('visitor')->after('is_active');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'course_id')) {
                $table->unsignedBigInteger('course_id')->nullable()->after('target_audience');
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            }
        });

        // Seed existing rows to 'visitor'
        DB::table('chatbot_knowledge_bases')->whereNull('target_audience')->update(['target_audience' => 'visitor']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropColumn(['target_audience', 'course_id']);
        });
    }
};
