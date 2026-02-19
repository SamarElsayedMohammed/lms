<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_quiz_answers', function (Blueprint $table) {
            // Add the column if it doesn't exist
            if (!Schema::hasColumn('user_quiz_answers', 'user_quiz_attempt_id')) {
                $table->unsignedBigInteger('user_quiz_attempt_id')->nullable()->after('user_id');
                $table->foreign('user_quiz_attempt_id')
                      ->references('id')
                      ->on('user_quiz_attempts')
                      ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_quiz_answers', function (Blueprint $table) {
            if (Schema::hasColumn('user_quiz_answers', 'user_quiz_attempt_id')) {
                $table->dropForeign(['user_quiz_attempt_id']);
                $table->dropColumn('user_quiz_attempt_id');
            }
        });
    }
};
