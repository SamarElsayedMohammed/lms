<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ChatbotKnowledgeBase;
use App\Models\ChatbotVectorChunk;
use App\Models\Course\Course;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use App\Services\TextChunkingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessKnowledgeIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public ?int $knowledgeBaseId = null,
        public ?int $courseId = null,
        public string $botType = 'course'
    ) {}

    public function handle(
        DocumentParserService $parser,
        TextChunkingService $chunker,
        EmbeddingService $embedder
    ): void {
        Log::info("ProcessKnowledgeIngestionJob started", [
            'knowledge_base_id' => $this->knowledgeBaseId,
            'course_id' => $this->courseId,
            'bot_type' => $this->botType,
        ]);

        $rawText = '';
        $title = '';
        $filePath = null;
        $fileType = null;
        $targetCourseId = $this->courseId;
        $knowledgeEntry = null;
        $courseModel = null;

        if ($this->knowledgeBaseId) {
            $knowledgeEntry = ChatbotKnowledgeBase::find($this->knowledgeBaseId);
            if (!$knowledgeEntry) {
                Log::warning("Knowledge base entry not found: {$this->knowledgeBaseId}");
                return;
            }

            $knowledgeEntry->update([
                'processing_status' => 'processing',
                'failure_reason' => null,
            ]);

            $title = $knowledgeEntry->title;
            $targetCourseId = $knowledgeEntry->course_id ?: $this->courseId;
            $filePath = $knowledgeEntry->file_path;
            $fileType = $knowledgeEntry->file_type;
            $rawText = $knowledgeEntry->content ?? '';
        }

        if ($targetCourseId) {
            $courseModel = Course::find($targetCourseId);
            if ($courseModel) {
                $courseModel->update([
                    'ai_processing_status' => 'processing',
                    'ai_failure_reason' => null,
                ]);
            }
        }

        try {
            // Extract text from uploaded file if path exists
            if ($filePath && empty($rawText)) {
                $fullPath = storage_path('app/public/' . ltrim($filePath, '/'));
                if (!file_exists($fullPath)) {
                    $fullPath = public_path($filePath);
                }

                if (file_exists($fullPath)) {
                    $rawText = $parser->extractText($fullPath, $fileType);
                    if ($knowledgeEntry) {
                        $knowledgeEntry->update(['content' => $rawText]);
                    }
                }
            }

            $normalizedText = $parser->normalizeText($rawText);
            if (empty($normalizedText)) {
                throw new \RuntimeException("Extracted knowledge text is empty.");
            }

            $contentHash = $chunker->computeHash($normalizedText);

            $chunks = $chunker->chunkText($normalizedText);
            if (empty($chunks)) {
                throw new \RuntimeException("Could not create any chunks from knowledge text.");
            }

            $preparedChunks = [];
            foreach ($chunks as $c) {
                $preparedChunks[] = [
                    'bot_type' => $this->botType,
                    'course_id' => $targetCourseId,
                    'knowledge_base_id' => $this->knowledgeBaseId,
                    'source_type' => $filePath ? 'file' : 'text',
                    'title' => $title ?: ($courseModel ? $courseModel->title : 'Global Knowledge'),
                    'chunk_index' => $c['index'],
                    'chunk_text' => $c['text'],
                    'embedding' => $embedder->generateEmbedding($c['text']),
                    'token_count' => $c['token_count'],
                    'content_hash' => $c['hash'],
                    'is_active' => true,
                ];
            }

            DB::beginTransaction();

            $deleteQuery = ChatbotVectorChunk::where('bot_type', $this->botType);
            if ($this->knowledgeBaseId) {
                $deleteQuery->where('knowledge_base_id', $this->knowledgeBaseId);
            } elseif ($targetCourseId) {
                $deleteQuery->where('course_id', $targetCourseId);
            }
            $deleteQuery->delete();

            $chunkCount = 0;
            foreach ($preparedChunks as $row) {
                ChatbotVectorChunk::create($row);
                $chunkCount++;
            }

            // Update KnowledgeBase status to ready
            if ($knowledgeEntry) {
                $knowledgeEntry->update([
                    'processing_status' => 'ready',
                    'chunk_count' => $chunkCount,
                    'content_hash' => $contentHash,
                    'indexed_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);
            }

            // Update Course status to ready
            if ($courseModel) {
                $courseModel->update([
                    'ai_knowledge_content' => $normalizedText,
                    'ai_processing_status' => 'ready',
                    'ai_chunk_count' => $chunkCount,
                    'ai_indexed_at' => now(),
                    'ai_failed_at' => null,
                    'ai_failure_reason' => null,
                ]);
            }

            DB::commit();

            Log::info("ProcessKnowledgeIngestionJob completed successfully", [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'course_id' => $targetCourseId,
                'chunks_created' => $chunkCount,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error("ProcessKnowledgeIngestionJob failed: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            if ($knowledgeEntry) {
                $knowledgeEntry->update([
                    'processing_status' => 'failed',
                    'failed_at' => now(),
                    'failure_reason' => $e->getMessage(),
                ]);
            }

            if ($courseModel) {
                $courseModel->update([
                    'ai_processing_status' => 'failed',
                    'ai_failed_at' => now(),
                    'ai_failure_reason' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
