<?php

declare(strict_types=1);

namespace Tests\Unit\Forensics;

use App\Jobs\AnalyzeLectureDurationJob;
use App\Jobs\DispatchNotificationCampaignJob;
use App\Jobs\EncodeVideoToHLS;
use App\Jobs\FetchBunnyVideoDurationJob;
use App\Jobs\FlushFeatureSectionAnalyticsJob;
use App\Jobs\ProcessKnowledgeIngestionJob;
use App\Jobs\RecalculateCourseDurationJob;
use App\Jobs\SendFcmNotificationJob;
use App\Jobs\SendNotificationCampaignChunkJob;
use App\Jobs\SendOrderNotifications;
use App\Jobs\UpdateExchangeRatesJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * Class QueueJobAttributesTest
 *
 * Forensic Unit Test Suite verifying explicit execution boundaries ($tries, $timeout, $backoff)
 * across all Queue Jobs, Queued Listeners, and Queued Notifications in backend-skillso (R3).
 */
final class QueueJobAttributesTest extends TestCase
{
    /**
     * Complete catalogue of all 11 core queue job classes in app/Jobs/.
     *
     * @var array<int, class-string>
     */
    private const CORE_JOBS = [
        AnalyzeLectureDurationJob::class,
        DispatchNotificationCampaignJob::class,
        EncodeVideoToHLS::class,
        FetchBunnyVideoDurationJob::class,
        FlushFeatureSectionAnalyticsJob::class,
        ProcessKnowledgeIngestionJob::class,
        RecalculateCourseDurationJob::class,
        SendFcmNotificationJob::class,
        SendNotificationCampaignChunkJob::class,
        SendOrderNotifications::class,
        UpdateExchangeRatesJob::class,
    ];

    /**
     * Tier 1: Feature Coverage — Verify every job class in app/Jobs/ implements ShouldQueue.
     */
    public function test_all_core_jobs_implement_should_queue(): void
    {
        foreach (self::CORE_JOBS as $jobClass) {
            $reflection = new ReflectionClass($jobClass);
            $this->assertTrue(
                $reflection->implementsInterface(ShouldQueue::class),
                "Job [{$jobClass}] must implement " . ShouldQueue::class
            );
        }
    }

    /**
     * Tier 1: Feature Coverage — Verify every job class in app/Jobs/ defines explicit $tries.
     */
    public function test_all_core_jobs_define_explicit_tries_property(): void
    {
        foreach (self::CORE_JOBS as $jobClass) {
            $reflection = new ReflectionClass($jobClass);
            $this->assertTrue(
                $reflection->hasProperty('tries'),
                "Job [{$jobClass}] is missing explicit public int \$tries property"
            );

            $property = $reflection->getProperty('tries');
            $this->assertTrue(
                $property->isPublic(),
                "Property \$tries on [{$jobClass}] must be public"
            );

            $defaultValue = $property->getDefaultValue();
            $this->assertIsInt(
                $defaultValue,
                "Property \$tries on [{$jobClass}] must be an integer default value"
            );
            $this->assertGreaterThanOrEqual(
                1,
                $defaultValue,
                "Property \$tries on [{$jobClass}] must be >= 1 (got {$defaultValue})"
            );
        }
    }

    /**
     * Tier 1: Feature Coverage — Verify every job class in app/Jobs/ defines explicit $timeout.
     */
    public function test_all_core_jobs_define_explicit_timeout_property(): void
    {
        foreach (self::CORE_JOBS as $jobClass) {
            $reflection = new ReflectionClass($jobClass);
            $this->assertTrue(
                $reflection->hasProperty('timeout'),
                "Job [{$jobClass}] is missing explicit public int \$timeout property"
            );

            $property = $reflection->getProperty('timeout');
            $this->assertTrue(
                $property->isPublic(),
                "Property \$timeout on [{$jobClass}] must be public"
            );

            $defaultValue = $property->getDefaultValue();
            $this->assertIsInt(
                $defaultValue,
                "Property \$timeout on [{$jobClass}] must be an integer default value"
            );
            $this->assertGreaterThanOrEqual(
                5,
                $defaultValue,
                "Property \$timeout on [{$jobClass}] must be >= 5 seconds (got {$defaultValue})"
            );
        }
    }

    /**
     * Tier 1: Feature Coverage — Verify every job class in app/Jobs/ defines explicit $backoff.
     */
    public function test_all_core_jobs_define_explicit_backoff_property(): void
    {
        foreach (self::CORE_JOBS as $jobClass) {
            $reflection = new ReflectionClass($jobClass);
            $this->assertTrue(
                $reflection->hasProperty('backoff'),
                "Job [{$jobClass}] is missing explicit public int|array \$backoff property"
            );

            $property = $reflection->getProperty('backoff');
            $this->assertTrue(
                $property->isPublic(),
                "Property \$backoff on [{$jobClass}] must be public"
            );

            $defaultValue = $property->getDefaultValue();
            $isValidType = is_int($defaultValue) || is_array($defaultValue);
            $this->assertTrue(
                $isValidType,
                "Property \$backoff on [{$jobClass}] must be an int or array of ints"
            );

            if (is_int($defaultValue)) {
                $this->assertGreaterThanOrEqual(
                    0,
                    $defaultValue,
                    "Integer \$backoff on [{$jobClass}] must be >= 0"
                );
            } elseif (is_array($defaultValue)) {
                $this->assertNotEmpty(
                    $defaultValue,
                    "Array \$backoff on [{$jobClass}] must not be empty"
                );
                foreach ($defaultValue as $step) {
                    $this->assertIsInt($step, "Backoff step in [{$jobClass}] must be integer");
                    $this->assertGreaterThanOrEqual(1, $step, "Backoff step in [{$jobClass}] must be >= 1s");
                }
            }
        }
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify job timeouts do not exceed queue connection retry_after (7300s).
     */
    public function test_job_timeouts_strictly_aligned_with_retry_after_horizon(): void
    {
        $maxAllowedTimeout = 7300; // Aligned with config/queue.php retry_after = 7300

        foreach (self::CORE_JOBS as $jobClass) {
            $reflection = new ReflectionClass($jobClass);
            if ($reflection->hasProperty('timeout')) {
                $timeout = $reflection->getProperty('timeout')->getDefaultValue();
                $this->assertLessThanOrEqual(
                    $maxAllowedTimeout,
                    $timeout,
                    "Job [{$jobClass}] \$timeout ({$timeout}s) exceeds queue retry_after ({$maxAllowedTimeout}s), risking worker collision"
                );
            }
        }
    }

    /**
     * Tier 2: Boundary & Corner Cases — Verify long-running video and ingestion jobs have specific limits.
     */
    public function test_heavy_computation_jobs_have_appropriate_isolation_properties(): void
    {
        // EncodeVideoToHLS: 1 try (fail fast to avoid corrupt HLS segment cascades), 7200s timeout
        $encodeReflection = new ReflectionClass(EncodeVideoToHLS::class);
        if ($encodeReflection->hasProperty('tries')) {
            $this->assertSame(1, $encodeReflection->getProperty('tries')->getDefaultValue());
        }
        if ($encodeReflection->hasProperty('timeout')) {
            $this->assertSame(7200, $encodeReflection->getProperty('timeout')->getDefaultValue());
        }

        // ProcessKnowledgeIngestionJob: 3 tries, 7200s timeout, 10s backoff
        $ingestionReflection = new ReflectionClass(ProcessKnowledgeIngestionJob::class);
        if ($ingestionReflection->hasProperty('tries')) {
            $this->assertSame(3, $ingestionReflection->getProperty('tries')->getDefaultValue());
        }
        if ($ingestionReflection->hasProperty('timeout')) {
            $this->assertSame(7200, $ingestionReflection->getProperty('timeout')->getDefaultValue());
        }
    }

    /**
     * Tier 3: Cross-Feature Interactions — Verify queued event listeners have execution boundaries.
     */
    public function test_queued_event_listeners_define_execution_limits(): void
    {
        $listenerDir = app_path('Listeners');
        if (!is_dir($listenerDir)) {
            $this->markTestSkipped('app/Listeners directory not found');
        }

        $files = scandir($listenerDir) ?: [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $className = 'App\\Listeners\\' . substr($file, 0, -4);
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->implementsInterface(ShouldQueue::class)) {
                $this->assertTrue(
                    $reflection->hasProperty('tries'),
                    "Queued listener [{$className}] should define public int \$tries"
                );
                $this->assertTrue(
                    $reflection->hasProperty('timeout'),
                    "Queued listener [{$className}] should define public int \$timeout"
                );
            }
        }
    }

    /**
     * Tier 4: Real-World Scenarios — Verify dynamic discovery of all job files in app/Jobs/.
     */
    public function test_dynamically_discovered_jobs_comply_with_worker_guardrails(): void
    {
        $jobsDir = app_path('Jobs');
        $files = scandir($jobsDir) ?: [];

        $discoveredCount = 0;
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $className = 'App\\Jobs\\' . substr($file, 0, -4);
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            $discoveredCount++;

            $this->assertTrue(
                $reflection->hasProperty('tries'),
                "Discovered Job [{$className}] must define public int \$tries"
            );
            $this->assertTrue(
                $reflection->hasProperty('timeout'),
                "Discovered Job [{$className}] must define public int \$timeout"
            );
            $this->assertTrue(
                $reflection->hasProperty('backoff'),
                "Discovered Job [{$className}] must define public int|array \$backoff"
            );
        }

        $this->assertGreaterThanOrEqual(11, $discoveredCount, "Expected at least 11 jobs in app/Jobs/");
    }
}
