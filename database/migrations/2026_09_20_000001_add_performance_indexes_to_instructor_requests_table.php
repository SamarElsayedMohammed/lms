<?php

declare(strict_types=1);

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
        Schema::table('instructor_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('instructor_requests', 'email')) {
                // Ensure email has an index for fast lookup
                try {
                    $table->index('email', 'instructor_requests_email_index');
                } catch (\Throwable) {}
            }

            if (Schema::hasColumn('instructor_requests', 'user_id') && Schema::hasColumn('instructor_requests', 'status')) {
                try {
                    $table->index(['user_id', 'status'], 'instructor_requests_user_status_index');
                } catch (\Throwable) {}
            }

            if (Schema::hasColumn('instructor_requests', 'email') && Schema::hasColumn('instructor_requests', 'status')) {
                try {
                    $table->index(['email', 'status'], 'instructor_requests_email_status_index');
                } catch (\Throwable) {}
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instructor_requests', function (Blueprint $table): void {
            try {
                $table->dropIndex('instructor_requests_email_index');
            } catch (\Throwable) {}

            try {
                $table->dropIndex('instructor_requests_user_status_index');
            } catch (\Throwable) {}

            try {
                $table->dropIndex('instructor_requests_email_status_index');
            } catch (\Throwable) {}
        });
    }
};
