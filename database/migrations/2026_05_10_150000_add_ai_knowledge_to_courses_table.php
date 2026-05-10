<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('ai_knowledge_file', 500)->nullable()->after('is_featured');
            $table->longText('ai_knowledge_content')->nullable()->after('ai_knowledge_file');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['ai_knowledge_file', 'ai_knowledge_content']);
        });
    }
};
