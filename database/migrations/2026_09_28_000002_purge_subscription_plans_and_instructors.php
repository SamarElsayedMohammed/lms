<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Completely purges:
     * - Subscription plans, prices, and subscriptions
     * - Instructors, instructor details, requests, and instructor role mappings
     * - All dummy/test orders, transactions, enrollments, and users
     * Strictly preserves:
     * - All 16 Courses and full curriculum (reassigned to Super Admin ID 4)
     * - Categories, FAQs, and CMS home/banner sections
     * - Super Admin User (ID 4)
     */
    public function up(): void
    {
        Log::info('[Purge Plans & Instructors Migration] Starting execution...');

        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');

        try {
            // 1. Reassign all courses to Super Admin (ID 4)
            if (DB::getSchemaBuilder()->hasTable('courses')) {
                DB::table('courses')->update(['user_id' => 4]);
            }

            // 2. Tables to truncate
            $tables = [
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

                // Certificates & Progress
                'course_certificates',
                'certificates',
                'quiz_certificates',
                'enrollments',
                'user_course_tracks',
                'user_course_chapter_tracks',
                'user_course_progress',
                'user_curriculum_trackings',
                'video_progress',
                'lecture_user_tracks',
                'user_quiz_attempts',
                'user_quiz_answers',

                // Reviews & Social
                'ratings',
                'course_discussions',
                'cart_promo_codes',
                'carts',
                'wishlists',
                'webinar_registrations',
                'webinars',
                'promo_code_course',
                'promo_redemptions',
                'promo_codes',

                // Support & Notifications
                'contact_message_replies',
                'contact_messages',
                'helpdesk_replies',
                'helpdesk_questions',
                'helpdesk_group_requests',
                'helpdesk_groups',
                'user_notification_reads',
                'user_notifications',
                'notification_campaigns',
                'notifications',

                // Chatbot & Logs
                'chatbot_messages',
                'chatbot_conversations',
                'admin_audit_logs',
                'search_histories',
                'failed_jobs',
                'telescope_entries_tags',
                'telescope_entries',
                'telescope_monitoring',
                'user_devices',
                'user_fcm_tokens',
                'user_assignment_files',
                'user_assignment_submissions',
            ];

            foreach ($tables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            // 3. Clean users except Super Admin (ID 4)
            if (DB::getSchemaBuilder()->hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->whereNotIn('tokenable_id', [4])->delete();
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
                DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->whereNotIn('model_id', [4])->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')->where('model_type', 'App\\Models\\User')->whereNotIn('model_id', [4])->delete();
            }

            if (DB::getSchemaBuilder()->hasTable('users')) {
                DB::table('users')->whereNotIn('id', [4])->delete();
                // Ensure Super Admin is not marked as instructor
                DB::table('users')->where('id', 4)->update(['is_instructor' => 0]);
            }

            // 4. Reset auto-increments
            $tablesToReset = [
                'orders', 'order_courses', 'certificates', 'course_certificates',
                'enrollments', 'ratings', 'carts', 'wallet_histories',
                'webinars', 'promo_codes', 'contact_messages', 'instructors',
                'subscription_plans', 'subscription_plan_prices',
                'chatbot_conversations', 'chatbot_messages'
            ];
            foreach ($tablesToReset as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1;");
                }
            }

            Log::info('[Purge Plans & Instructors Migration] Completed successfully!');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible cleanup migration
    }
};
