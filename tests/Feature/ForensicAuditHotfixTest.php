<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ForceJsonResponseToSnakeCase;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ForensicAuditHotfixTest extends TestCase
{
    public function test_kashier_rejects_unverified_success_payloads(): void
    {
        $source = $this->source('app/Http/Controllers/KashierController.php');

        $this->assertStringNotContainsString(
            '!$isVerified && !$isSuccess',
            $source,
            'Unverified SUCCESS callbacks must not skip signature checks.',
        );
    }

    public function test_ingestion_generates_embeddings_before_opening_a_db_transaction(): void
    {
        $source = $this->source('app/Jobs/ProcessKnowledgeIngestionJob.php');
        $embedPos = strpos($source, 'generateEmbedding(');
        $transactionPos = strpos($source, 'DB::transaction(');
        if ($transactionPos === false) {
            $transactionPos = strpos($source, 'DB::beginTransaction(');
        }

        $this->assertNotFalse($embedPos);
        $this->assertNotFalse($transactionPos);
        $this->assertLessThan(
            $transactionPos,
            $embedPos,
            'Outbound embedding HTTP must not run while a DB transaction is open.',
        );
    }

    public function test_certificate_pdf_generation_does_not_sleep_or_scan_the_whole_directory(): void
    {
        $source = $this->source('app/Traits/CertificatePdfGeneratorTrait.php');

        $this->assertStringNotContainsString('sleep(', $source);
        $this->assertStringNotContainsString("files('certificates')", $source);
    }

    public function test_healthcheck_requires_php_fpm_and_nginx_not_only_pid1(): void
    {
        $script = $this->source('docker/healthcheck.sh');

        $this->assertStringContainsString('kill -0 1', $script);
        $this->assertStringContainsString('php-fpm', $script);
        $this->assertStringContainsString('nginx', $script);
        $this->assertStringContainsString('/proc/', $script);
        $this->assertStringNotContainsString('pgrep', $script);
        $this->assertStringNotContainsString('curl', $script);
        $this->assertStringNotContainsString('PORT', $script);
    }

    public function test_idempotency_middleware_clears_processing_lock_on_exceptions(): void
    {
        Cache::flush();
        $request = Request::create('/api/test', 'POST');
        $request->headers->set('Idempotency-Key', 'audit-lock-1');

        try {
            (new IdempotencyMiddleware())->handle($request, static function () {
                throw new RuntimeException('controller exploded');
            });
            $this->fail('Expected the controller exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('controller exploded', $exception->getMessage());
        }

        $this->assertNull(Cache::get('idempotency:'.hash('sha256', implode('|', [
            '',
            'POST',
            'api/test',
            'audit-lock-1',
        ]))));
    }

    public function test_change_row_order_requires_the_same_table_permission_as_change_status(): void
    {
        $source = $this->source('app/Http/Controllers/Controller.php');
        $method = $this->methodBody($source, 'changeRowOrder', 'changeStatus');

        $this->assertStringContainsString('noPermissionThenSendJson', $method);
    }

    public function test_nixpacks_queue_workers_run_as_www_data(): void
    {
        $nixpacks = $this->source('nixpacks.toml');

        foreach (['worker-laravel-default', 'worker-laravel-video', 'worker-scheduler'] as $program) {
            $this->assertMatchesRegularExpression(
                '/\[program:'.$program.'\][\s\S]*?user\s*=\s*www-data/',
                $nixpacks,
                $program.' must not write root-owned files into storage.',
            );
        }
    }

    public function test_fcm_job_chunks_tokens_instead_of_one_unbounded_loop(): void
    {
        $source = $this->source('app/Jobs/SendFcmNotificationJob.php');

        $this->assertStringContainsString('array_chunk', $source);
        $this->assertLessThan(
            strpos($source, 'FirebaseHelper::send') ?: PHP_INT_MAX,
            strpos($source, 'array_chunk') ?: 0,
        );
    }

    public function test_snake_case_middleware_skips_large_report_payloads(): void
    {
        $middleware = new ForceJsonResponseToSnakeCase();
        $request = Request::create('/api/admin/reports/course', 'GET');

        $response = $middleware->handle($request, static function () {
            return new JsonResponse(['totalCourses' => 1]);
        });

        $this->assertSame(['totalCourses' => 1], $response->getData(true));
    }

    public function test_embedding_search_caps_vectors_below_the_previous_thousand_row_scan(): void
    {
        $source = $this->source('app/Services/EmbeddingService.php');

        $this->assertStringContainsString('SEARCH_CANDIDATE_LIMIT', $source);
        $this->assertDoesNotMatchRegularExpression('/limit\(\s*1000\s*\)/', $source);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertNotFalse($source, $path);

        return $source;
    }

    private function methodBody(string $source, string $method, string $nextMethod): string
    {
        $start = strpos($source, "function {$method}");
        $end = strpos($source, "function {$nextMethod}", $start ?: 0);
        $this->assertNotFalse($start, $method);
        $this->assertNotFalse($end, $nextMethod);

        return substr($source, $start, $end - $start);
    }
}
