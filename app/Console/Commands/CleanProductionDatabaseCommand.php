<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanProductionDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'production:cleanup {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean all test/dummy data, subscription plans, and instructors for production while strictly preserving Courses, Categories, FAQs, and CMS.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('Are you sure you want to clean all dummy data, subscription plans, and instructors for production?')) {
            $this->warn('Operation cancelled.');
            return 0;
        }

        $this->info('Starting production database cleanup...');

        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');

        try {
            // Reassign courses to Super Admin
            if (DB::getSchemaBuilder()->hasTable('courses')) {
                DB::table('courses')->update(['user_id' => 4]);
                $this->info('✔ Reassigned all 16 courses to Super Admin (ID: 4)');
            }

            $tablesToTruncate = [
                // Instructors
                'course_instructors', 'instructors', 'instructor_personal_details',
                'instructor_other_details', 'instructor_social_medias', 'instructor_requests', 'team_members',
                // Subscription Plans
                'subscription_plan_prices', 'subscription_plans', 'promo_code_subscription_plan',
                'subscriptions', 'subscription_payments',
                // Financials & Orders
                'order_courses', 'order_promo_codes', 'orders',
                'transactions', 'payment_transactions', 'store_transactions', 'store_notification_events',
                'wallet_histories', 'wallet_top_up_attempts', 'manual_deposits', 'withdrawal_requests', 'refund_requests',
                'course_certificates', 'certificates', 'quiz_certificates',
                'enrollments', 'user_course_tracks', 'user_course_chapter_tracks',
                'user_course_progress', 'user_curriculum_trackings', 'video_progress', 'lecture_user_tracks',
                'user_quiz_attempts', 'user_quiz_answers',
                'ratings', 'course_discussions',
                'cart_promo_codes', 'carts', 'wishlists',
                'webinar_registrations', 'webinars',
                'promo_code_course', 'promo_redemptions', 'promo_codes',
                'contact_message_replies', 'contact_messages', 'helpdesk_replies', 'helpdesk_questions',
                'helpdesk_group_requests', 'helpdesk_groups',
                'user_notification_reads', 'user_notifications', 'notification_campaigns', 'notifications',
                'chatbot_messages', 'chatbot_conversations',
                'admin_audit_logs', 'search_histories', 'failed_jobs',
                'telescope_entries_tags', 'telescope_entries', 'telescope_monitoring',
                'user_devices', 'user_fcm_tokens', 'user_assignment_files', 'user_assignment_submissions',
            ];

            foreach ($tablesToTruncate as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->line("✔ Truncated {$table}");
                }
            }

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
                $deletedUsers = DB::table('users')->whereNotIn('id', [4])->delete();
                $this->info("✔ Deleted {$deletedUsers} dummy users and instructors. Preserved Super Admin (ID 4).");
            }

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

            $this->info('✔ Production database cleanup completed successfully!');
            return 0;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }
}
