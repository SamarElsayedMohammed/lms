<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'ai_knowledge_url')) {
                $table->string('ai_knowledge_url', 500)->nullable()->after('ai_knowledge_file');
            }
        });

        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            if (! Schema::hasColumn('chatbot_knowledge_bases', 'source_url')) {
                $table->string('source_url', 500)->nullable()->after('file_type');
            }
        });

        DB::table('courses')
            ->whereNull('ai_knowledge_url')
            ->where('ai_knowledge_file', 'like', 'http%')
            ->orderBy('id')
            ->select(['id', 'ai_knowledge_file'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $path = (string) $row->ai_knowledge_file;
                    if (str_contains($path, 'b-cdn.net') || str_contains($path, 'mediadelivery.net')) {
                        continue;
                    }

                    DB::table('courses')->where('id', $row->id)->update([
                        'ai_knowledge_url' => $path,
                        'ai_knowledge_file' => null,
                    ]);
                }
            });

        DB::table('chatbot_knowledge_bases')
            ->where('file_type', 'url')
            ->whereNull('source_url')
            ->orderBy('id')
            ->select(['id', 'file_path'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $path = trim((string) $row->file_path);
                    if ($path === '' || ! str_starts_with($path, 'http')) {
                        continue;
                    }

                    DB::table('chatbot_knowledge_bases')->where('id', $row->id)->update([
                        'source_url' => $path,
                        'file_path' => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'ai_knowledge_url')) {
                $table->dropColumn('ai_knowledge_url');
            }
        });

        Schema::table('chatbot_knowledge_bases', function (Blueprint $table) {
            if (Schema::hasColumn('chatbot_knowledge_bases', 'source_url')) {
                $table->dropColumn('source_url');
            }
        });
    }
};
