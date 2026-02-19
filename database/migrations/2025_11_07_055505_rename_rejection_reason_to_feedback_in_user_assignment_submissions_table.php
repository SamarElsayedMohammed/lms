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
        if (Schema::hasTable('user_assignment_submissions')) {
            Schema::table('user_assignment_submissions', function (Blueprint $table) {
                if (Schema::hasColumn('user_assignment_submissions', 'rejection_reason')) {
                    $table->renameColumn('rejection_reason', 'feedback');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_assignment_submissions')) {
            Schema::table('user_assignment_submissions', function (Blueprint $table) {
                if (Schema::hasColumn('user_assignment_submissions', 'feedback')) {
                    $table->renameColumn('feedback', 'rejection_reason');
                }
            });
        }
    }
};
