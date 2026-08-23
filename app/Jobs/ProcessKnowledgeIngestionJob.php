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

    public const WRITE_BATCH_SIZE = 25;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(
        public ?int $knowledgeBaseId = null,
        public ?int $courseId = null,
        public string $botType = 'course'
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        DocumentParserService $parser,
        TextChunkingService $chunker,
        EmbeddingService $embedder
    ): void {
        Log::info('ProcessKnowledgeIngestionJob started', [
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
        $rowSpool = null;

        if ($this->knowledgeBaseId) {
            $knowledgeEntry = ChatbotKnowledgeBase::find($this->knowledgeBaseId);
            if (! $knowledgeEntry) {
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
            // File uploads are parsed from disk. Never treat leftover binary bytes as UTF-8 text.
            $rawText = $filePath ? '' : ($knowledgeEntry->content ?? '');
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
                $fullPath = storage_path('app/public/'.ltrim($filePath, '/'));
                if (! file_exists($fullPath)) {
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
                throw new \RuntimeException('Extracted knowledge text is empty.');
            }

            $contentHash = $chunker->computeHash($normalizedText);

            $chunks = $chunker->chunkText($normalizedText);
            if (empty($chunks)) {
                throw new \RuntimeException('Could not create any chunks from knowledge text.');
            }

            $timestamp = now()->toDateTimeString();
            $rowSpool = tmpfile();
            if ($rowSpool === false) {
                throw new \RuntimeException('Unable to create temporary ingestion spool.');
            }

            $chunkCount = 0;
            foreach ($chunks as $chunk) {
                $row = [
                    'bot_type' => $this->botType,
                    'course_id' => $targetCourseId,
                    'knowledge_base_id' => $this->knowledgeBaseId,
                    'source_type' => $filePath ? 'file' : 'text',
                    'title' => $title ?: ($courseModel ? $courseModel->title : 'Global Knowledge'),
                    'chunk_index' => $chunk['index'],
                    'chunk_text' => $chunk['text'],
                    'embedding' => json_encode(
                        $embedder->generateEmbedding($chunk['text']),
                        JSON_THROW_ON_ERROR
                    ),
                    'token_count' => $chunk['token_count'],
                    'content_hash' => $chunk['hash'],
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $encodedRow = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
                if (fwrite($rowSpool, $encodedRow) !== strlen($encodedRow)) {
                    throw new \RuntimeException('Unable to write the ingestion spool.');
                }
                $chunkCount++;
            }

            unset($chunks);
            rewind($rowSpool);

            $deleteQuery = ChatbotVectorChunk::where('bot_type', $this->botType);
            if ($this->knowledgeBaseId) {
                $deleteQuery->where('knowledge_base_id', $this->knowledgeBaseId);
            } elseif ($targetCourseId) {
                $deleteQuery->where('course_id', $targetCourseId);
            }

            DB::transaction(function () use (
                $deleteQuery,
                $rowSpool,
                $knowledgeEntry,
                $courseModel,
                $chunkCount,
                $contentHash,
                $normalizedText,
            ): void {
                $deleteQuery->delete();

                $batchRows = [];
                while (($line = fgets($rowSpool)) !== false) {
                    $batchRows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (count($batchRows) === self::WRITE_BATCH_SIZE) {
                        ChatbotVectorChunk::insert($batchRows);
                        $batchRows = [];
                    }
                }
                if ($batchRows !== []) {
                    ChatbotVectorChunk::insert($batchRows);
                }

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
            });

            Log::info('ProcessKnowledgeIngestionJob completed successfully', [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'course_id' => $targetCourseId,
                'chunks_created' => $chunkCount,
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('ProcessKnowledgeIngestionJob failed: '.$e->getMessage(), [
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
        } finally {
            if (is_resource($rowSpool)) {
                fclose($rowSpool);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $failureReason = trim($exception->getMessage());
        if ($failureReason === '') {
            $failureReason = 'Knowledge ingestion failed before completion.';
        }

        $failure = [
            'failed_at' => now(),
            'failure_reason' => $failureReason,
        ];

        if ($this->knowledgeBaseId) {
            ChatbotKnowledgeBase::whereKey($this->knowledgeBaseId)->update([
                ...$failure,
                'processing_status' => 'failed',
            ]);
        }

        if ($this->courseId) {
            Course::whereKey($this->courseId)->update([
                'ai_processing_status' => 'failed',
                'ai_failed_at' => $failure['failed_at'],
                'ai_failure_reason' => $failure['failure_reason'],
            ]);
        }
    }
}
