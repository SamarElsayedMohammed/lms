<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Cleans test & dummy data for production while strictly preserving:
     * - All 16 Courses, chapters, lectures, videos, and curriculum (reassigned to Super Admin ID 4)
     * - All Categories, FAQs, and CMS sections / banners
     * - Super Admin Account (superadmin@elms.com)
     * Wipes:
     * - All dummy users and instructors
     * - All subscription plans and prices
     * - All test orders, certificates, enrollments, and progress
     */
    public function up(): void
    {
        Log::info('[Production Cleanup Migration] Starting safe cleanup with instructors and subscription plans...');

        $isMysql = DB::getDriverName() === 'mysql';
        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        try {
            // 1. Reassign all courses to Super Admin (ID: 4) so all 16 courses remain intact and valid
            if (DB::getSchemaBuilder()->hasTable('courses')) {
                DB::table('courses')->update(['user_id' => 4]);
            }

            // 2. Truncate test/dummy orders, transactions, plans, and instructors
            $tablesToTruncate = [
                // Instructors
                'course_instructors',
                'instructors',
                'instructor_personal_details',
                'instructor_other_details',
                'instructor_social_medias',
                'instructor_requests',
                'team_members',
                // Subscription Plans
                'subscription_plan_prices',
                'subscription_plans',
                'promo_code_subscription_plan',
                'subscriptions',
                'subscription_payments',
                // Financials & Orders
                'order_courses',
                'order_promo_codes',
                'orders',
                'transactions',
                'payment_transactions',
                'store_transactions',
                'store_notification_events',
                'wallet_histories',
                'wallet_top_up_attempts',
                'manual_deposits',
                'withdrawal_requests',
                'refund_requests',
                // Certificates
                'course_certificates',
                'certificates',
                'quiz_certificates',
                // Student enrollments, tracks & progress
                'enrollments',
                'user_course_tracks',
                'user_course_chapter_tracks',
                'user_course_progress',
                'user_curriculum_trackings',
                'video_progress',
                'lecture_user_tracks',
                'user_quiz_attempts',
                'user_quiz_answers',
                // Reviews & discussions
                'ratings',
                'course_discussions',
                // Carts & Wishlists
                'cart_promo_codes',
                'carts',
                'wishlists',
                // Webinars & Registrations
                'webinar_registrations',
                'webinars',
                // Promo Codes
                'promo_code_course',
                'promo_redemptions',
                'promo_codes',
                // Support & Contact
                'contact_message_replies',
                'contact_messages',
                'helpdesk_replies',
                'helpdesk_questions',
                'helpdesk_group_requests',
                'helpdesk_groups',
                // Notifications
                'user_notification_reads',
                'user_notifications',
                'notification_campaigns',
                'notifications',
                // Chatbot chat sessions
                'chatbot_messages',
                'chatbot_conversations',
                // System logs & audit
                'admin_audit_logs',
                'search_histories',
                'failed_jobs',
                'telescope_entries_tags',
                'telescope_entries',
                'telescope_monitoring',
                // Devices & tokens
                'user_devices',
                'user_fcm_tokens',
                'user_assignment_files',
                'user_assignment_submissions',
            ];

            foreach ($tablesToTruncate as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            // 3. Keep Super Admin (ID: 4) only
            if (DB::getSchemaBuilder()->hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->whereNotIn('tokenable_id', [4])
                    ->delete();
            }

            if (DB::getSchemaBuilder()->hasTable('user_credit_cards')) {
                DB::table('user_credit_cards')->whereNotIn('user_id', [4])->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('user_billing_details')) {
                DB::table('user_billing_details')->whereNotIn('user_id', [4])->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('user_social_accounts')) {
                DB::table('user_social_accounts')->whereNotIn('user_id', [4])->delete();
            }

            if (DB::getSchemaBuilder()->hasTable('model_has_roles')) {
                DB::table('model_has_roles')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereNotIn('model_id', [4])
                    ->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereNotIn('model_id', [4])
                    ->delete();
            }

            // Clean all users except Super Admin (ID 4)
            if (DB::getSchemaBuilder()->hasTable('users')) {
                $deletedUsers = DB::table('users')->whereNotIn('id', [4])->delete();
                Log::info("[Production Cleanup Migration] Deleted {$deletedUsers} dummy users and instructors.");
            }

            // 4. Reset auto-increments
            $tablesToReset = [
                'orders', 'order_courses', 'certificates', 'course_certificates',
                'enrollments', 'ratings', 'carts', 'wallet_histories',
                'webinars', 'promo_codes', 'contact_messages', 'instructors',
                'subscription_plans', 'subscription_plan_prices',
                'chatbot_conversations', 'chatbot_messages'
            ];
            if ($isMysql) {
                foreach ($tablesToReset as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1;");
                    }
                }
            }

            Log::info('[Production Cleanup Migration] Cleanup completed successfully!');
        } finally {
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way cleanup migration.
    }
};
