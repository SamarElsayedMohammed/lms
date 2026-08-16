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
        Schema::table('webinars', function (Blueprint $table) {
            if (!Schema::hasColumn('webinars', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('start_at')
                      ->comment('Timestamp when the 1-hour starting soon reminder was dispatched');
            }
        });

        Schema::table('webinar_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('webinar_registrations', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('payment_status')
                      ->comment('Pending payment expiration timestamp to release reserved capacity');
            }
            if (!Schema::hasColumn('webinar_registrations', 'attended_at')) {
                $table->timestamp('attended_at')->nullable()->after('expires_at')
                      ->comment('Timestamp when user joined/checked in to the webinar');
            }
            if (!Schema::hasColumn('webinar_registrations', 'attended')) {
                $table->boolean('attended')->default(false)->after('attended_at')
                      ->comment('True if attendee check-in was recorded');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            if (Schema::hasColumn('webinars', 'reminder_sent_at')) {
                $table->dropColumn('reminder_sent_at');
            }
        });

        Schema::table('webinar_registrations', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('webinar_registrations', 'expires_at')) $cols[] = 'expires_at';
            if (Schema::hasColumn('webinar_registrations', 'attended_at')) $cols[] = 'attended_at';
            if (Schema::hasColumn('webinar_registrations', 'attended')) $cols[] = 'attended';
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
