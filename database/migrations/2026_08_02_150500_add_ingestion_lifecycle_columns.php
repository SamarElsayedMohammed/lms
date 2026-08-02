<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'processing_status')) {
                $table->string('processing_status', 30)->default('ready')->after('is_active'); // not_configured, uploaded, queued, processing, ready, failed
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'chunk_count')) {
                $table->integer('chunk_count')->default(0)->after('processing_status');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'indexed_at')) {
                $table->timestamp('indexed_at')->nullable()->after('chunk_count');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('indexed_at');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('failed_at');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('failure_reason');
            }
            if (!Schema::hasColumn('chatbot_knowledge_bases', 'language')) {
                $table->string('language', 10)->default('ar')->after('content_hash');
            }
        });

        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'ai_processing_status')) {
                $table->string('ai_processing_status', 30)->default('not_configured')->after('chatbot_max_tokens');
            }
            if (!Schema::hasColumn('courses', 'ai_chunk_count')) {
                $table->integer('ai_chunk_count')->default(0)->after('ai_processing_status');
            }
            if (!Schema::hasColumn('courses', 'ai_indexed_at')) {
                $table->timestamp('ai_indexed_at')->nullable()->after('ai_chunk_count');
            }
            if (!Schema::hasColumn('courses', 'ai_failed_at')) {
                $table->timestamp('ai_failed_at')->nullable()->after('ai_indexed_at');
            }
            if (!Schema::hasColumn('courses', 'ai_failure_reason')) {
                $table->text('ai_failure_reason')->nullable()->after('ai_failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'chunk_count',
                'indexed_at',
                'failed_at',
                'failure_reason',
                'content_hash',
                'language',
            ]);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'ai_processing_status',
                'ai_chunk_count',
                'ai_indexed_at',
                'ai_failed_at',
                'ai_failure_reason',
            ]);
        });
    }
};
