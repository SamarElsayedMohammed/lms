<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ForensicAuditRound2Test extends TestCase
{
    public function test_order_gateway_initiation_happens_after_db_commit(): void
    {
        $source = $this->source('app/Http/Controllers/API/OrderApiController.php');

        preg_match_all(
            '/DB::commit\(\);\s*(?:\/\/[^\n]*\n\s*)*\$paymentInit\s*=\s*\$paymentService->initiate\(/s',
            $source,
            $matches
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($matches[0]),
            'placeOrder and placeOrderFromCart must commit before calling the payment gateway.'
        );
    }

    public function test_subscription_tracking_http_runs_after_commit(): void
    {
        $source = $this->source('app/Http/Controllers/API/Admin/SubscriptionAdminApiController.php');
        $approve = $this->methodBody($source, 'approve', 'downloadReceipt');

        $commitPos = strpos($approve, 'DB::commit()');
        $facebookPos = strpos($approve, 'sendFacebookEvent');
        $gaPos = strpos($approve, 'sendGA4Event');

        $this->assertNotFalse($commitPos);
        $this->assertNotFalse($facebookPos);
        $this->assertNotFalse($gaPos);
        $this->assertLessThan($facebookPos, $commitPos);
        $this->assertLessThan($gaPos, $commitPos);
    }

    public function test_manual_deposit_status_update_locks_the_row(): void
    {
        $source = $this->source('app/Http/Controllers/API/Admin/ManualDepositAdminApiController.php');
        $method = $this->methodBody($source, 'updateDepositStatus', '');

        $this->assertStringContainsString('lockForUpdate()', $method);
        $lockPos = strpos($method, 'lockForUpdate()');
        $statusPos = strpos($method, "status !== 'pending'");
        $this->assertNotFalse($lockPos);
        $this->assertNotFalse($statusPos);
        $this->assertLessThan($statusPos, $lockPos);
    }

    public function test_queue_drivers_dispatch_jobs_after_db_commit(): void
    {
        $source = $this->source('config/queue.php');

        $this->assertDoesNotMatchRegularExpression(
            "/'driver'\\s*=>\\s*'(database|redis)'[\\s\\S]{0,400}'after_commit'\\s*=>\\s*false/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'driver'\\s*=>\\s*'database'[\\s\\S]{0,400}'after_commit'\\s*=>\\s*true/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'driver'\\s*=>\\s*'redis'[\\s\\S]{0,400}'after_commit'\\s*=>\\s*true/",
            $source
        );
    }

    public function test_chatbot_knowledge_does_not_store_raw_binary_file_bytes(): void
    {
        $source = $this->source('app/Http/Controllers/API/Admin/ChatbotAdminApiController.php');

        $this->assertStringNotContainsString(
            "file_get_contents(\$file->getRealPath())",
            $source
        );
    }

    public function test_instructor_notification_insert_does_not_guess_primary_keys(): void
    {
        $source = $this->source('app/Http/Controllers/API/InstructorApiController.php');

        $this->assertStringNotContainsString("notifications')->max('id')", $source);
        $this->assertStringNotContainsString("'id' => \$maxId + 1", $source);
    }

    public function test_embedding_service_reads_cached_config_not_env(): void
    {
        $source = $this->source('app/Services/EmbeddingService.php');

        $this->assertStringNotContainsString("env('AI_PROVIDER'", $source);
        $this->assertStringNotContainsString("env('OPENAI_API_KEY'", $source);
        $this->assertStringContainsString("config('services.ai.provider'", $source);
    }

    public function test_instructor_report_does_not_dereference_null_details(): void
    {
        $source = $this->source('app/Http/Controllers/ReportsController.php');

        $this->assertStringNotContainsString(
            '$instructor->instructor_details->status',
            $source
        );
        $this->assertStringNotContainsString(
            '$instructor->instructor_details->type',
            $source
        );
    }

    public function test_sales_summary_does_not_hydrate_every_order(): void
    {
        $source = $this->source('app/Http/Controllers/ReportsController.php');
        $method = $this->methodBody($source, 'getSalesSummaryData', 'getDetailedSalesData');

        $this->assertStringNotContainsString('$orders = $query->get();', $method);
    }

    public function test_settings_external_validator_has_a_curl_timeout(): void
    {
        $source = $this->source('app/Http/Controllers/SettingsController.php');

        $this->assertStringContainsString('CURLOPT_TIMEOUT', $source);
        $this->assertStringContainsString('CURLOPT_CONNECTTIMEOUT', $source);
    }

    public function test_exception_handler_does_not_log_tokens_or_raw_uploads(): void
    {
        $source = $this->source('app/Exceptions/Handler.php');

        $this->assertStringNotContainsString('plainTextToken', $source);
        $this->assertStringNotContainsString('$params = $request->all();', $source);
    }

    public function test_home_category_counts_are_cached(): void
    {
        $source = $this->source('app/Http/Controllers/API/HomeApiController.php');
        $method = $this->methodBody($source, 'getCategoriesWithCourseCount', 'getFeatureSections');

        $this->assertStringContainsString('cacheRemember', $method);
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
        $this->assertNotFalse($start, $method);

        if ($nextMethod === '') {
            return substr($source, $start);
        }

        $end = strpos($source, "function {$nextMethod}", $start);
        $this->assertNotFalse($end, $nextMethod);

        return substr($source, $start, $end - $start);
    }
}
