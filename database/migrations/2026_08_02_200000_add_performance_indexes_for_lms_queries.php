<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safely add index ignoring duplicate key errors if already present.
     */
    private function safeAddIndex(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Safe fallback if index already exists
        }
    }

    /**
     * Safely drop index ignoring errors if not present.
     */
    private function safeDropIndex(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Safe fallback if index does not exist
        }
    }

    public function up(): void
    {
        $this->safeAddIndex('courses', ['is_active', 'status', 'approval_status', 'category_id'], 'idx_courses_active_status_cat');
        $this->safeAddIndex('courses', ['is_active', 'status', 'approval_status', 'is_featured'], 'idx_courses_active_status_feat');
        $this->safeAddIndex('course_chapters', ['course_id', 'is_active', 'sort_order'], 'idx_chapters_course_active_sort');
        $this->safeAddIndex('course_chapter_lectures', ['course_chapter_id', 'is_active', 'sort_order'], 'idx_lectures_chap_active_sort');
        $this->safeAddIndex('subscription_payments', ['subscription_id', 'payment_method'], 'idx_sub_payments_sub_method');
        $this->safeAddIndex('certificates', ['user_id', 'course_id'], 'idx_certs_user_course');
        $this->safeAddIndex('chatbot_messages', ['conversation_id', 'created_at'], 'idx_chat_msgs_conv_created');
        $this->safeAddIndex('users', ['is_active', 'created_at'], 'idx_users_active_created');
        $this->safeAddIndex('subscriptions', ['user_id', 'status', 'ends_at'], 'idx_subs_user_status_ends');
        $this->safeAddIndex('user_curriculum_tracking', ['user_id', 'course_id', 'status'], 'idx_track_user_course_status');
        $this->safeAddIndex('video_progress', ['user_id', 'lecture_id', 'updated_at'], 'idx_vid_prog_user_lecture');
        $this->safeAddIndex('helpdesk_questions', ['user_id', 'created_at'], 'idx_help_user_created');
    }

    public function down(): void
    {
        $this->safeDropIndex('courses', 'idx_courses_active_status_cat');
        $this->safeDropIndex('courses', 'idx_courses_active_status_feat');
        $this->safeDropIndex('course_chapters', 'idx_chapters_course_active_sort');
        $this->safeDropIndex('course_chapter_lectures', 'idx_lectures_chap_active_sort');
        $this->safeDropIndex('subscription_payments', 'idx_sub_payments_sub_method');
        $this->safeDropIndex('certificates', 'idx_certs_user_course');
        $this->safeDropIndex('chatbot_messages', 'idx_chat_msgs_conv_created');
        $this->safeDropIndex('users', 'idx_users_active_created');
        $this->safeDropIndex('subscriptions', 'idx_subs_user_status_ends');
        $this->safeDropIndex('user_curriculum_tracking', 'idx_track_user_course_status');
        $this->safeDropIndex('video_progress', 'idx_vid_prog_user_lecture');
        $this->safeDropIndex('helpdesk_questions', 'idx_help_user_created');
    }
};
