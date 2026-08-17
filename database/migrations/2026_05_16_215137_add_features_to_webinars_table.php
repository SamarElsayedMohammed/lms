<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            if (!Schema::hasColumn('webinars', 'features')) {
                $table->json('features')->nullable()->after('description')
                      ->comment('Array of bullet-point features for the landing page');
            }
            if (!Schema::hasColumn('webinars', 'max_attendees')) {
                $table->unsignedInteger('max_attendees')->default(0)->after('price')
                      ->comment('0 = unlimited');
            }
            if (!Schema::hasColumn('webinars', 'tags')) {
                $table->string('tags')->nullable()->after('max_attendees')
                      ->comment('Comma-separated tags for filtering/search');
            }
            if (!Schema::hasColumn('webinars', 'recording_url')) {
                $table->string('recording_url')->nullable()->after('join_url');
            }
        });

        // Fix provider enum to include google_meet
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement("ALTER TABLE webinars MODIFY COLUMN provider ENUM('zoom','jitsi','google_meet','custom') DEFAULT 'jitsi'");
            } catch (\Exception $e) {
                // Ignore if fails
            }
        }
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('webinars', 'features')) $columnsToDrop[] = 'features';
            if (Schema::hasColumn('webinars', 'max_attendees')) $columnsToDrop[] = 'max_attendees';
            if (Schema::hasColumn('webinars', 'tags')) $columnsToDrop[] = 'tags';
            if (Schema::hasColumn('webinars', 'recording_url')) $columnsToDrop[] = 'recording_url';
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement("ALTER TABLE webinars MODIFY COLUMN provider ENUM('zoom','jitsi','custom') DEFAULT 'jitsi'");
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }
};
