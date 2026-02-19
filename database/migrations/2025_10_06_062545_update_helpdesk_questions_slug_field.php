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
        // Step 1: Add slug column (nullable for now)
        if (!Schema::hasColumn('helpdesk_questions', 'slug')) {
            Schema::table('helpdesk_questions', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('title');
            });
        }

        // Step 2: Generate slugs for existing records that don't have them
        $questions = \App\Models\HelpdeskQuestion::whereNull('slug')->orWhere('slug', '')->get();
        foreach ($questions as $question) {
            $question->slug = $question->generateUniqueSlug($question->title);
            $question->save();
        }

        // Step 3: Make slug unique and not nullable
        Schema::table('helpdesk_questions', function (Blueprint $table) {
            $table->string('slug')->unique()->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_questions', 'slug')) {
        Schema::table('helpdesk_questions', function (Blueprint $table) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
        });
        }
    }
};
