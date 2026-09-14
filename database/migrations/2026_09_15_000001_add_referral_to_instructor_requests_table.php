<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('instructor_requests', 'referral')) {
                $table->string('referral', 255)->nullable()->after('youtube_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instructor_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('instructor_requests', 'referral')) {
                $table->dropColumn('referral');
            }
        });
    }
};
