<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Safely updates stored notification records from "سكيلسو" to "سكيلزو" without destructive changes.
     */
    public function up(): void
    {
        // 1. Update Laravel default notifications table (data JSON column)
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'data')) {
            try {
                $notifications = DB::table('notifications')
                    ->where('data', 'like', '%سكيلسو%')
                    ->get(['id', 'data']);

                foreach ($notifications as $notification) {
                    $raw = is_string($notification->data) ? $notification->data : json_encode($notification->data, JSON_UNESCAPED_UNICODE);
                    $updated = str_replace('سكيلسو', 'سكيلزو', $raw);
                    DB::table('notifications')
                        ->where('id', $notification->id)
                        ->update(['data' => $updated]);
                }
            } catch (\Throwable $e) {
                // Non-fatal safety catch
                \Illuminate\Support\Facades\Log::warning('Migration brand update warning on notifications table: ' . $e->getMessage());
            }
        }

        // 2. Update legacy_notifications table if present
        if (Schema::hasTable('legacy_notifications')) {
            try {
                if (Schema::hasColumn('legacy_notifications', 'title')) {
                    $legacyTitles = DB::table('legacy_notifications')
                        ->where('title', 'like', '%سكيلسو%')
                        ->get(['id', 'title']);

                    foreach ($legacyTitles as $row) {
                        DB::table('legacy_notifications')
                            ->where('id', $row->id)
                            ->update(['title' => str_replace('سكيلسو', 'سكيلزو', (string) $row->title)]);
                    }
                }

                if (Schema::hasColumn('legacy_notifications', 'message')) {
                    $legacyMessages = DB::table('legacy_notifications')
                        ->where('message', 'like', '%سكيلسو%')
                        ->get(['id', 'message']);

                    foreach ($legacyMessages as $row) {
                        DB::table('legacy_notifications')
                            ->where('id', $row->id)
                            ->update(['message' => str_replace('سكيلسو', 'سكيلزو', (string) $row->message)]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Migration brand update warning on legacy_notifications table: ' . $e->getMessage());
            }
        }

        // 3. Update user_notifications table if present
        if (Schema::hasTable('user_notifications')) {
            try {
                if (Schema::hasColumn('user_notifications', 'title')) {
                    $userNotifTitles = DB::table('user_notifications')
                        ->where('title', 'like', '%سكيلسو%')
                        ->get(['id', 'title']);

                    foreach ($userNotifTitles as $row) {
                        DB::table('user_notifications')
                            ->where('id', $row->id)
                            ->update(['title' => str_replace('سكيلسو', 'سكيلزو', (string) $row->title)]);
                    }
                }

                if (Schema::hasColumn('user_notifications', 'message')) {
                    $userNotifMessages = DB::table('user_notifications')
                        ->where('message', 'like', '%سكيلسو%')
                        ->get(['id', 'message']);

                    foreach ($userNotifMessages as $row) {
                        DB::table('user_notifications')
                            ->where('id', $row->id)
                            ->update(['message' => str_replace('سكيلسو', 'سكيلزو', (string) $row->message)]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Migration brand update warning on user_notifications table: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive: We intentionally do not revert correct brand name back to misspelling.
    }
};
