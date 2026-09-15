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
            if (!Schema::hasColumn('instructor_requests', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('instructor_requests', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (!Schema::hasColumn('instructor_requests', 'country')) {
                $table->string('country')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('instructor_requests', 'nationality')) {
                $table->string('nationality')->nullable()->after('country');
            }
            if (!Schema::hasColumn('instructor_requests', 'job_title')) {
                $table->string('job_title')->nullable()->after('specialty');
            }
            if (!Schema::hasColumn('instructor_requests', 'company')) {
                $table->string('company')->nullable()->after('job_title');
            }
            if (!Schema::hasColumn('instructor_requests', 'years_of_experience')) {
                $table->unsignedSmallInteger('years_of_experience')->default(0)->after('company');
            }
            if (!Schema::hasColumn('instructor_requests', 'linkedin_url')) {
                $table->string('linkedin_url', 500)->nullable()->after('experience_bio');
            }
            if (!Schema::hasColumn('instructor_requests', 'facebook_url')) {
                $table->string('facebook_url', 500)->nullable()->after('linkedin_url');
            }
            if (!Schema::hasColumn('instructor_requests', 'website_url')) {
                $table->string('website_url', 500)->nullable()->after('facebook_url');
            }
            if (!Schema::hasColumn('instructor_requests', 'youtube_url')) {
                $table->string('youtube_url', 500)->nullable()->after('website_url');
            }
            if (!Schema::hasColumn('instructor_requests', 'intro_video_type')) {
                $table->string('intro_video_type', 20)->nullable()->default('url')->after('youtube_url');
            }
            if (!Schema::hasColumn('instructor_requests', 'intro_video_url')) {
                $table->text('intro_video_url')->nullable()->after('intro_video_type');
            }
            if (!Schema::hasColumn('instructor_requests', 'intro_video_path')) {
                $table->string('intro_video_path')->nullable()->after('intro_video_url');
            }
            if (!Schema::hasColumn('instructor_requests', 'cv_path')) {
                $table->string('cv_path')->nullable()->after('intro_video_path');
            }
            if (!Schema::hasColumn('instructor_requests', 'cv_original_name')) {
                $table->string('cv_original_name')->nullable()->after('cv_path');
            }
            if (!Schema::hasColumn('instructor_requests', 'cv_size')) {
                $table->unsignedInteger('cv_size')->nullable()->after('cv_original_name');
            }
            if (!Schema::hasColumn('instructor_requests', 'profile_image_path')) {
                $table->string('profile_image_path')->nullable()->after('cv_size');
            }
            if (!Schema::hasColumn('instructor_requests', 'admin_notes')) {
                $table->text('admin_notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('instructor_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('admin_notes');
            }
            if (!Schema::hasColumn('instructor_requests', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('rejection_reason')->index();
            }
            if (!Schema::hasColumn('instructor_requests', 'reviewer_id')) {
                $table->unsignedBigInteger('reviewer_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('instructor_requests', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewer_id');
            }
        });

        // Change status column to string if it was enum to allow expanded statuses gracefully
        try {
            Schema::table('instructor_requests', function (Blueprint $table): void {
                $table->string('status', 30)->default('pending')->change();
            });
        } catch (\Throwable) {
            // Raw SQL fallback for MySQL/MariaDB in case schema change() encounters driver limitations
            try {
                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    \Illuminate\Support\Facades\DB::statement("ALTER TABLE instructor_requests MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending'");
                }
            } catch (\Throwable) {
                // Pass through
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instructor_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name',
                'last_name',
                'country',
                'nationality',
                'job_title',
                'company',
                'years_of_experience',
                'linkedin_url',
                'facebook_url',
                'website_url',
                'youtube_url',
                'intro_video_type',
                'intro_video_url',
                'intro_video_path',
                'cv_path',
                'cv_original_name',
                'cv_size',
                'profile_image_path',
                'admin_notes',
                'rejection_reason',
                'user_id',
                'reviewer_id',
                'reviewed_at',
            ]);
        });
    }
};
