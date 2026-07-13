<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            // visitor = for unauthenticated users
            // subscriber = for logged-in enrolled students
            // course = attached to a specific course (used by CourseKnowledgeManager)
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'target_audience')) {
                $table->string('target_audience')->default('visitor')->after('is_active');
            }

            if (!Schema::hasColumn('chatbot_knowledge_bases', 'course_id')) {
                $table->unsignedBigInteger('course_id')->nullable()->after('target_audience');
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            }
        });

        try {
            Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
                $table->index(['target_audience', 'course_id']);
            });
        } catch (\Exception $e) {}

        // Backfill existing entries with default 'visitor' audience
        if (Schema::hasColumn('chatbot_knowledge_bases', 'target_audience')) {
            \Illuminate\Support\Facades\DB::table('chatbot_knowledge_bases')
                ->whereNull('target_audience')
                ->orWhere('target_audience', '')
                ->update(['target_audience' => 'visitor']);
        }
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropIndex(['target_audience', 'course_id']);
            $table->dropColumn(['target_audience', 'course_id']);
        });
    }
};
