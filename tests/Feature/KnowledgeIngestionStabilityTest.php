<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeIngestionJob;
use App\Models\ChatbotKnowledgeBase;
use App\Services\DocumentParserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class KnowledgeIngestionStabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('chatbot_knowledge_bases');
        Schema::create('chatbot_knowledge_bases', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('target_audience')->default('visitor');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('processing_status')->default('pending');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('language', 10)->default('ar');
            $table->timestamps();
        });
    }

    public function test_ingestion_job_uses_a_dedicated_bounded_queue(): void
    {
        $job = new ProcessKnowledgeIngestionJob(knowledgeBaseId: 1);

        $this->assertSame('ingestion', $job->queue);
        $this->assertSame(7200, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan(7300, $job->timeout);
    }

    public function test_oversized_documents_are_rejected_before_parsing(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'oversized-ingestion-');
        $this->assertNotFalse($filePath);

        $handle = fopen($filePath, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, DocumentParserService::MAX_FILE_SIZE_BYTES + 1));
        fclose($handle);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Document exceeds the maximum allowed size of 25 MB.');

            (new DocumentParserService)->extractText($filePath, 'txt');
        } finally {
            @unlink($filePath);
        }
    }

    public function test_terminal_failure_is_saved_on_the_knowledge_entry(): void
    {
        $knowledgeEntry = ChatbotKnowledgeBase::create([
            'title' => 'Large document',
            'processing_status' => 'processing',
        ]);

        $job = new ProcessKnowledgeIngestionJob(knowledgeBaseId: $knowledgeEntry->id);
        $job->failed(new RuntimeException('Document exceeds the maximum allowed size of 25 MB.'));

        $knowledgeEntry->refresh();

        $this->assertSame('failed', $knowledgeEntry->processing_status);
        $this->assertSame(
            'Document exceeds the maximum allowed size of 25 MB.',
            $knowledgeEntry->failure_reason
        );
        $this->assertNotNull($knowledgeEntry->failed_at);
    }

    public function test_terminal_failure_without_an_exception_message_gets_a_clear_reason(): void
    {
        $knowledgeEntry = ChatbotKnowledgeBase::create([
            'title' => 'Timed out document',
            'processing_status' => 'processing',
        ]);

        $job = new ProcessKnowledgeIngestionJob(knowledgeBaseId: $knowledgeEntry->id);
        $job->failed(new RuntimeException);

        $this->assertSame(
            'Knowledge ingestion failed before completion.',
            $knowledgeEntry->fresh()->failure_reason
        );
    }

    public function test_ingestion_writes_are_explicitly_batched(): void
    {
        $jobSource = file_get_contents(app_path('Jobs/ProcessKnowledgeIngestionJob.php'));
        $this->assertNotFalse($jobSource);

        $this->assertSame(25, ProcessKnowledgeIngestionJob::WRITE_BATCH_SIZE);
        $this->assertStringContainsString(
            'array_chunk($preparedRows, self::WRITE_BATCH_SIZE)',
            $jobSource
        );
        $this->assertStringContainsString('ChatbotVectorChunk::insert($batchRows)', $jobSource);
        $this->assertStringNotContainsString('$preparedChunks', $jobSource);
    }

    public function test_both_deployment_paths_define_one_low_concurrency_ingestion_worker(): void
    {
        $supervisor = file_get_contents(base_path('docker/supervisor/supervisord.conf'));
        $nixpacks = file_get_contents(base_path('nixpacks.toml'));

        $this->assertNotFalse($supervisor);
        $this->assertNotFalse($nixpacks);

        $this->assertSame(1, substr_count($supervisor, '[program:ingestion-worker]'));
        $this->assertSame(1, substr_count($nixpacks, '[program:worker-laravel-ingestion]'));

        foreach ([$supervisor, $nixpacks] as $configuration) {
            $this->assertStringContainsString('--queue=ingestion', $configuration);
            $this->assertStringContainsString('--timeout=7200', $configuration);
            $this->assertStringContainsString('--tries=3', $configuration);
            $this->assertStringContainsString('--memory=384', $configuration);
            $this->assertMatchesRegularExpression('/numprocs\s*=\s*1/', $configuration);
        }
    }
}
