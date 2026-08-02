<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical Performance Index Migration for LMS & Full-Stack Queries.
     * STATICALLY JUSTIFIED — QUERY PLAN UNVERIFIED
     */
    public function up(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->index(['is_active', 'status', 'approval_status', 'category_id'], 'idx_courses_active_status_cat');
                $table->index(['is_active', 'status', 'approval_status', 'is_featured'], 'idx_courses_active_status_feat');
            });
        }

        if (Schema::hasTable('course_chapters')) {
            Schema::table('course_chapters', function (Blueprint $table) {
                $table->index(['course_id', 'is_active', 'sort_order'], 'idx_chapters_course_active_sort');
            });
        }

        if (Schema::hasTable('course_chapter_lectures')) {
            Schema::table('course_chapter_lectures', function (Blueprint $table) {
                $table->index(['course_chapter_id', 'is_active', 'sort_order'], 'idx_lectures_chap_active_sort');
            });
        }

        if (Schema::hasTable('subscription_payments')) {
            Schema::table('subscription_payments', function (Blueprint $table) {
                $table->index(['subscription_id', 'payment_method'], 'idx_sub_payments_sub_method');
            });
        }

        if (Schema::hasTable('certificates')) {
            Schema::table('certificates', function (Blueprint $table) {
                $table->index(['user_id', 'course_id'], 'idx_certs_user_course');
            });
        }

        if (Schema::hasTable('chatbot_messages')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'created_at'], 'idx_chat_msgs_conv_created');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index(['is_active', 'created_at'], 'idx_users_active_created');
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index(['user_id', 'status', 'ends_at'], 'idx_subs_user_status_ends');
            });
        }

        if (Schema::hasTable('user_curriculum_tracking')) {
            Schema::table('user_curriculum_tracking', function (Blueprint $table) {
                $table->index(['user_id', 'course_id', 'status'], 'idx_track_user_course_status');
            });
        }

        if (Schema::hasTable('video_progress')) {
            Schema::table('video_progress', function (Blueprint $table) {
                $table->index(['user_id', 'lecture_id', 'updated_at'], 'idx_vid_prog_user_lecture');
            });
        }

        if (Schema::hasTable('helpdesk_questions')) {
            Schema::table('helpdesk_questions', function (Blueprint $table) {
                $table->index(['user_id', 'created_at'], 'idx_help_user_created');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropIndex('idx_courses_active_status_cat');
                $table->dropIndex('idx_courses_active_status_feat');
            });
        }

        if (Schema::hasTable('course_chapters')) {
            Schema::table('course_chapters', function (Blueprint $table) {
                $table->dropIndex('idx_chapters_course_active_sort');
            });
        }

        if (Schema::hasTable('course_chapter_lectures')) {
            Schema::table('course_chapter_lectures', function (Blueprint $table) {
                $table->dropIndex('idx_lectures_chap_active_sort');
            });
        }

        if (Schema::hasTable('subscription_payments')) {
            Schema::table('subscription_payments', function (Blueprint $table) {
                $table->dropIndex('idx_sub_payments_sub_method');
            });
        }

        if (Schema::hasTable('certificates')) {
            Schema::table('certificates', function (Blueprint $table) {
                $table->dropIndex('idx_certs_user_course');
            });
        }

        if (Schema::hasTable('chatbot_messages')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->dropIndex('idx_chat_msgs_conv_created');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('idx_users_active_created');
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex('idx_subs_user_status_ends');
            });
        }

        if (Schema::hasTable('user_curriculum_tracking')) {
            Schema::table('user_curriculum_tracking', function (Blueprint $table) {
                $table->dropIndex('idx_track_user_course_status');
            });
        }

        if (Schema::hasTable('video_progress')) {
            Schema::table('video_progress', function (Blueprint $table) {
                $table->dropIndex('idx_vid_prog_user_lecture');
            });
        }

        if (Schema::hasTable('helpdesk_questions')) {
            Schema::table('helpdesk_questions', function (Blueprint $table) {
                $table->dropIndex('idx_help_user_created');
            });
        }
    }
};
