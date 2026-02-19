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
        Schema::table('user_quiz_attempts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])
                  ->default('pending')
                  ->after('score');
            $table->timestamp('completed_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at']);
        });
    }
};
