# Video Progress Segments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement segment-based video progress tracking to ensure users watch 100% of video content before marking it complete.

**Architecture:** Extend existing VideoProgress model with watched_segments JSON array. Each video is divided into 5-second segments. Frontend reports newly watched segment indices, backend validates and calculates accurate progress percentage.

**Tech Stack:** Laravel 11, PHP 8.1+, MySQL/MariaDB (JSON column), PHPUnit for testing

---

## Task 1: Database Migration

**Files:**
- Create: `database/migrations/2026_06_23_000000_add_segments_to_video_progress.php`

- [ ] **Step 1: Create migration file**

```bash
php artisan make:migration add_segments_to_video_progress
```

- [ ] **Step 2: Write migration code**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $table->json('watched_segments')->nullable()->after('watch_percentage');
            $table->unsignedInteger('segment_size')->default(5)->after('watched_segments');
            $table->unsignedInteger('total_segments')->default(0)->after('segment_size');
            $table->unsignedInteger('completed_segments')->default(0)->after('total_segments');
        });
    }

    public function down(): void
    {
        Schema::table('video_progress', function (Blueprint $table) {
            $table->dropColumn(['watched_segments', 'segment_size', 'total_segments', 'completed_segments']);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration completes successfully, new columns added to video_progress table.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat(db): add segment tracking columns to video_progress"
```

---

## Task 2: Update VideoProgress Model

**Files:**
- Modify: `app/Models/VideoProgress.php`

- [ ] **Step 1: Add new fillable fields**

In `app/Models/VideoProgress.php`, update the `$fillable` array:

```php
protected $fillable = [
    'user_id',
    'lecture_id',
    'watched_seconds',
    'total_seconds',
    'last_position',
    'watch_percentage',
    'is_completed',
    'completed_at',
    'watched_segments',    // NEW
    'segment_size',        // NEW
    'total_segments',      // NEW
    'completed_segments',  // NEW
];
```

- [ ] **Step 2: Add new casts**

Update the `$casts` array:

```php
protected $casts = [
    'is_completed' => 'boolean',
    'completed_at' => 'datetime',
    'watch_percentage' => 'float',
    'watched_segments' => 'array',  // NEW
];
```

- [ ] **Step 3: Add helper method for segment initialization**

Add this method to the VideoProgress class:

```php
/**
 * Initialize watched segments array for a video duration.
 *
 * @param int $totalDuration Total video duration in seconds
 * @param int $segmentSize Size of each segment in seconds
 * @return array Array of zeros representing unwatched segments
 */
public static function initializeSegments(int $totalDuration, int $segmentSize = 5): array
{
    $totalSegments = (int) ceil($totalDuration / $segmentSize);
    return array_fill(0, $totalSegments, 0);
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/VideoProgress.php
git commit -m "feat(model): add segment tracking fields to VideoProgress"
```

---

## Task 3: Update VideoProgressService - Core Logic

**Files:**
- Modify: `app/Services/VideoProgressService.php`

- [ ] **Step 1: Add segment constants**

At the top of the VideoProgressService class, add:

```php
public const DEFAULT_SEGMENT_SIZE = 5; // seconds
public const MAX_SEGMENTS_PER_REQUEST = 3; // anti-cheat: max segments reportable per 15-second update
```

- [ ] **Step 2: Add updateSegmentProgress method**

Add this new method to VideoProgressService:

```php
/**
 * Update progress using segment-based tracking.
 *
 * @param User $user
 * @param CourseChapterLecture $lecture
 * @param int $currentPosition Current playback position in seconds
 * @param int $totalDuration Total video duration in seconds
 * @param array $newlyWatchedSegments Array of segment indices that were newly watched
 * @return VideoProgress
 */
public function updateSegmentProgress(
    User $user,
    CourseChapterLecture $lecture,
    int $currentPosition,
    int $totalDuration,
    array $newlyWatchedSegments
): VideoProgress {
    $progress = $this->getOrCreateSegmentProgress($user, $lecture, $totalDuration);

    // Get existing watched segments or initialize
    $watchedSegments = $progress->watched_segments ?? 
        VideoProgress::initializeSegments($totalDuration, self::DEFAULT_SEGMENT_SIZE);

    // Mark newly watched segments
    foreach ($newlyWatchedSegments as $segmentIndex) {
        if ($segmentIndex >= 0 && $segmentIndex < $progress->total_segments) {
            $watchedSegments[$segmentIndex] = 1;
        }
    }

    // Calculate progress
    $completedSegments = array_sum($watchedSegments);
    $watchPercentage = $progress->total_segments > 0
        ? round(($completedSegments / $progress->total_segments) * 100, 2)
        : 0;

    // Check completion
    $wasAlreadyCompleted = $progress->is_completed;
    $isCompleted = $watchPercentage >= self::COMPLETION_THRESHOLD;
    $completedAt = $isCompleted && !$wasAlreadyCompleted ? now() : $progress->completed_at;

    // Also update legacy watched_seconds for backward compatibility
    $watchedSeconds = $completedSegments * self::DEFAULT_SEGMENT_SIZE;

    // Update record
    $progress->update([
        'watched_segments' => $watchedSegments,
        'completed_segments' => $completedSegments,
        'watch_percentage' => $watchPercentage,
        'watched_seconds' => $watchedSeconds,
        'last_position' => $currentPosition,
        'is_completed' => $isCompleted,
        'completed_at' => $completedAt,
    ]);

    // Sync curriculum tracking if newly completed
    if ($isCompleted && !$wasAlreadyCompleted && $lecture->course_chapter_id) {
        $this->syncCurriculumTracking($user->id, $lecture);

        $chapter = CourseChapter::find($lecture->course_chapter_id);
        if ($chapter) {
            CurriculumItemCompleted::dispatch($user->id, $chapter->course_id);
        }
    }

    return $progress->fresh();
}
```

- [ ] **Step 3: Add getOrCreateSegmentProgress helper**

Add this private method:

```php
/**
 * Get existing progress or create new with segment initialization.
 */
private function getOrCreateSegmentProgress(
    User $user,
    CourseChapterLecture $lecture,
    int $totalDuration
): VideoProgress {
    $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

    if ($progress === null) {
        $totalSegments = (int) ceil($totalDuration / self::DEFAULT_SEGMENT_SIZE);
        $watchedSegments = array_fill(0, $totalSegments, 0);

        $progress = VideoProgress::create([
            'user_id' => $user->id,
            'lecture_id' => $lecture->id,
            'watched_seconds' => 0,
            'total_seconds' => $totalDuration,
            'last_position' => 0,
            'watch_percentage' => 0,
            'is_completed' => false,
            'watched_segments' => $watchedSegments,
            'segment_size' => self::DEFAULT_SEGMENT_SIZE,
            'total_segments' => $totalSegments,
            'completed_segments' => 0,
        ]);
    }

    return $progress;
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/VideoProgressService.php
git commit -m "feat(service): add segment-based progress tracking logic"
```

---

## Task 4: Update VideoProgressService - Seek Validation

**Files:**
- Modify: `app/Services/VideoProgressService.php`

- [ ] **Step 1: Add getMaxSeekablePosition method**

Add this method to VideoProgressService:

```php
/**
 * Get maximum position user can seek to (highest continuously watched point from start).
 *
 * @param VideoProgress $progress
 * @return int Maximum seekable position in seconds
 */
public function getMaxSeekablePosition(VideoProgress $progress): int
{
    // If completed, allow seeking anywhere
    if ($progress->is_completed) {
        return $progress->total_seconds;
    }

    $watchedSegments = $progress->watched_segments ?? [];
    $maxContinuousIndex = 0;

    // Find highest continuous watched segment from start
    foreach ($watchedSegments as $index => $watched) {
        if ($watched) {
            $maxContinuousIndex = $index + 1;
        } else {
            break; // First unwatched segment breaks the chain
        }
    }

    return $maxContinuousIndex * ($progress->segment_size ?? self::DEFAULT_SEGMENT_SIZE);
}
```

- [ ] **Step 2: Add getProgressWithSeekInfo method**

Add this method to return progress with seek information:

```php
/**
 * Get progress with seek information for API response.
 *
 * @param User $user
 * @param CourseChapterLecture $lecture
 * @return array|null
 */
public function getProgressWithSeekInfo(User $user, CourseChapterLecture $lecture): ?array
{
    $progress = VideoProgress::forUser($user->id)->forLecture($lecture->id)->first();

    if ($progress === null) {
        return null;
    }

    return [
        'watched_seconds' => $progress->watched_seconds,
        'total_seconds' => $progress->total_seconds,
        'last_position' => $progress->last_position,
        'watch_percentage' => (float) $progress->watch_percentage,
        'is_completed' => $progress->is_completed,
        'watched_segments' => $progress->watched_segments ?? [],
        'total_segments' => $progress->total_segments ?? 0,
        'completed_segments' => $progress->completed_segments ?? 0,
        'can_seek_to' => $this->getMaxSeekablePosition($progress),
        'resume_from' => $progress->last_position,
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/VideoProgressService.php
git commit -m "feat(service): add seek validation and progress info methods"
```

---

## Task 5: Update LectureProgressApiController

**Files:**
- Modify: `app/Http/Controllers/API/LectureProgressApiController.php`

- [ ] **Step 1: Update updateProgress method**

Replace the existing `updateProgress` method with:

```php
/**
 * Update video watch progress using segment tracking.
 */
public function updateProgress(Request $request, int $lectureId): JsonResponse
{
    // Support both old format (watched_seconds) and new format (newly_watched_segments)
    $hasSegments = $request->has('newly_watched_segments');

    if ($hasSegments) {
        $validated = $request->validate([
            'current_position' => 'required|integer|min:0',
            'total_duration' => 'required|integer|min:1',
            'newly_watched_segments' => 'required|array|max:' . VideoProgressService::MAX_SEGMENTS_PER_REQUEST,
            'newly_watched_segments.*' => 'integer|min:0',
        ]);
    } else {
        // Legacy format support
        $validated = $request->validate([
            'watched_seconds' => 'required|integer|min:0',
            'last_position' => 'required|integer|min:0',
            'total_seconds' => 'required|integer|min:1',
        ]);
    }

    $lecture = CourseChapterLecture::find($lectureId);
    if ($lecture === null) {
        return $this->notFound('Lecture not found');
    }

    $user = Auth::user();
    if ($user === null) {
        return $this->unauthorized();
    }

    if ($hasSegments) {
        // New segment-based tracking
        $progress = $this->videoProgressService->updateSegmentProgress(
            $user,
            $lecture,
            (int) $validated['current_position'],
            (int) $validated['total_duration'],
            $validated['newly_watched_segments']
        );

        return $this->ok(
            data: [
                'watch_percentage' => (float) $progress->watch_percentage,
                'is_completed' => $progress->is_completed,
                'completed_segments' => $progress->completed_segments,
                'total_segments' => $progress->total_segments,
                'last_position' => $progress->last_position,
                'can_seek_to' => $this->videoProgressService->getMaxSeekablePosition($progress),
            ],
            message: 'Progress updated'
        );
    }

    // Legacy format - use existing method
    $progress = $this->videoProgressService->updateProgress(
        $user,
        $lecture,
        (int) $validated['watched_seconds'],
        (int) $validated['last_position'],
        (int) $validated['total_seconds']
    );

    return $this->ok(
        data: [
            'watched_seconds' => $progress->watched_seconds,
            'watch_percentage' => (float) $progress->watch_percentage,
            'is_completed' => $progress->is_completed,
            'last_position' => $progress->last_position,
        ],
        message: 'Progress updated'
    );
}
```

- [ ] **Step 2: Update getProgress method**

Replace the existing `getProgress` method with:

```php
/**
 * Get video progress for a lecture (with segment info).
 */
public function getProgress(int $lectureId): JsonResponse
{
    $lecture = CourseChapterLecture::find($lectureId);
    if ($lecture === null) {
        return $this->notFound('Lecture not found');
    }

    $user = Auth::user();
    if ($user === null) {
        return $this->unauthorized();
    }

    $progress = $this->videoProgressService->getProgressWithSeekInfo($user, $lecture);

    if ($progress === null) {
        return $this->ok(data: [
            'watched_seconds' => 0,
            'total_seconds' => 0,
            'watch_percentage' => 0.0,
            'last_position' => 0,
            'is_completed' => false,
            'watched_segments' => [],
            'total_segments' => 0,
            'completed_segments' => 0,
            'can_seek_to' => 0,
            'resume_from' => 0,
        ]);
    }

    return $this->ok(data: $progress);
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/API/LectureProgressApiController.php
git commit -m "feat(api): update progress endpoints for segment tracking"
```

---

## Task 6: Add Use Statement Imports

**Files:**
- Modify: `app/Services/VideoProgressService.php`

- [ ] **Step 1: Verify imports at top of VideoProgressService**

Ensure these imports are at the top of the file:

```php
<?php

namespace App\Services;

use App\Events\CurriculumItemCompleted;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
```

- [ ] **Step 2: Commit if changes made**

```bash
git add app/Services/VideoProgressService.php
git commit -m "chore: ensure proper imports in VideoProgressService"
```

---

## Task 7: Unit Tests for VideoProgressService

**Files:**
- Create: `tests/Unit/VideoProgressServiceSegmentsTest.php`

- [ ] **Step 1: Create test file**

```php
<?php

namespace Tests\Unit;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VideoProgressServiceSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private VideoProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $featureFlagService = Mockery::mock(FeatureFlagService::class);
        $featureFlagService->shouldReceive('isEnabled')->andReturn(true);
        
        $this->service = new VideoProgressService($featureFlagService);
    }

    public function test_initialize_segments_creates_correct_array(): void
    {
        // 30 second video with 5-second segments = 6 segments
        $segments = VideoProgress::initializeSegments(30, 5);
        
        $this->assertCount(6, $segments);
        $this->assertEquals([0, 0, 0, 0, 0, 0], $segments);
    }

    public function test_initialize_segments_rounds_up_partial_segments(): void
    {
        // 33 second video with 5-second segments = 7 segments (ceil)
        $segments = VideoProgress::initializeSegments(33, 5);
        
        $this->assertCount(7, $segments);
    }

    public function test_update_segment_progress_creates_new_record(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        $progress = $this->service->updateSegmentProgress(
            $user,
            $lecture,
            currentPosition: 15,
            totalDuration: 60,
            newlyWatchedSegments: [0, 1, 2]
        );

        $this->assertInstanceOf(VideoProgress::class, $progress);
        $this->assertEquals(12, $progress->total_segments); // 60/5 = 12
        $this->assertEquals(3, $progress->completed_segments);
        $this->assertEquals(25.0, $progress->watch_percentage); // 3/12 = 25%
        $this->assertFalse($progress->is_completed);
    }

    public function test_update_segment_progress_accumulates_segments(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // First update: segments 0, 1, 2
        $this->service->updateSegmentProgress(
            $user, $lecture, 15, 60, [0, 1, 2]
        );

        // Second update: segments 3, 4
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 25, 60, [3, 4]
        );

        $this->assertEquals(5, $progress->completed_segments);
        $this->assertEquals(41.67, $progress->watch_percentage); // 5/12 rounded
    }

    public function test_duplicate_segments_are_not_counted_twice(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // First update: segments 0, 1
        $this->service->updateSegmentProgress(
            $user, $lecture, 10, 60, [0, 1]
        );

        // Second update: segment 1 again + new segment 2
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 15, 60, [1, 2]
        );

        $this->assertEquals(3, $progress->completed_segments);
    }

    public function test_100_percent_marks_completed(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // 30 second video = 6 segments
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 30, 30, [0, 1, 2, 3, 4, 5]
        );

        $this->assertTrue($progress->is_completed);
        $this->assertEquals(100.0, $progress->watch_percentage);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_invalid_segment_indices_are_ignored(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // 30 second video = 6 segments (indices 0-5)
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 30, 30, [0, 1, 100, -1, 999]
        );

        $this->assertEquals(2, $progress->completed_segments); // Only 0 and 1 are valid
    }

    public function test_get_max_seekable_position_continuous_from_start(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // Watch segments 0, 1, 2 continuously, then skip to 5
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 30, 60, [0, 1, 2, 5]
        );

        // Can only seek to end of continuous segments (0, 1, 2)
        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(15, $maxSeek); // 3 segments * 5 seconds
    }

    public function test_completed_video_allows_full_seek(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // Complete all segments
        $progress = $this->service->updateSegmentProgress(
            $user, $lecture, 30, 30, [0, 1, 2, 3, 4, 5]
        );

        $maxSeek = $this->service->getMaxSeekablePosition($progress);
        $this->assertEquals(30, $maxSeek); // Full duration
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
php artisan test tests/Unit/VideoProgressServiceSegmentsTest.php
```

Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/VideoProgressServiceSegmentsTest.php
git commit -m "test: add unit tests for segment-based progress tracking"
```

---

## Task 8: Feature Tests for API Endpoints

**Files:**
- Create: `tests/Feature/LectureProgressSegmentsApiTest.php`

- [ ] **Step 1: Create feature test file**

```php
<?php

namespace Tests\Feature;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LectureProgressSegmentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_progress_with_segments(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'watch_percentage',
                    'is_completed',
                    'completed_segments',
                    'total_segments',
                    'last_position',
                    'can_seek_to',
                ],
            ]);

        $this->assertEquals(25.0, $response->json('data.watch_percentage'));
        $this->assertEquals(3, $response->json('data.completed_segments'));
    }

    public function test_update_progress_rejects_too_many_segments(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 50,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2, 3, 4, 5], // More than MAX_SEGMENTS_PER_REQUEST
        ]);

        $response->assertStatus(422);
    }

    public function test_get_progress_returns_segment_info(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        // First create some progress
        $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        // Then fetch it
        $response = $this->actingAs($user)->getJson("/api/lecture/{$lecture->id}/progress");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'watched_seconds',
                    'total_seconds',
                    'watch_percentage',
                    'last_position',
                    'is_completed',
                    'watched_segments',
                    'total_segments',
                    'completed_segments',
                    'can_seek_to',
                    'resume_from',
                ],
            ]);

        $this->assertIsArray($response->json('data.watched_segments'));
    }

    public function test_legacy_format_still_works(): void
    {
        $user = User::factory()->create();
        $lecture = CourseChapterLecture::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/lecture/{$lecture->id}/progress", [
            'watched_seconds' => 30,
            'last_position' => 30,
            'total_seconds' => 60,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'watched_seconds',
                    'watch_percentage',
                    'is_completed',
                    'last_position',
                ],
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $lecture = CourseChapterLecture::factory()->create();

        $response = $this->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(401);
    }

    public function test_nonexistent_lecture_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/lecture/99999/progress", [
            'current_position' => 15,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2],
        ]);

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run feature tests**

```bash
php artisan test tests/Feature/LectureProgressSegmentsApiTest.php
```

Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/LectureProgressSegmentsApiTest.php
git commit -m "test: add feature tests for segment progress API endpoints"
```

---

## Task 9: Create CourseChapterLecture Factory (if missing)

**Files:**
- Check/Create: `database/factories/CourseChapterLectureFactory.php`

- [ ] **Step 1: Check if factory exists**

```bash
ls database/factories/ | grep -i lecture
```

If factory doesn't exist, create it:

- [ ] **Step 2: Create factory if missing**

```php
<?php

namespace Database\Factories\Course\CourseChapter\Lecture;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CourseChapterLectureFactory extends Factory
{
    protected $model = CourseChapterLecture::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_chapter_id' => CourseChapter::factory(),
            'title' => $this->faker->sentence(3),
            'slug' => Str::slug($this->faker->sentence(3)),
            'type' => 'file',
            'file' => null,
            'file_extension' => 'mp4',
            'hours' => 0,
            'minutes' => 5,
            'seconds' => 0,
            'description' => $this->faker->paragraph(),
            'chapter_order' => $this->faker->numberBetween(1, 10),
            'is_active' => true,
            'free_preview' => false,
        ];
    }
}
```

- [ ] **Step 3: Commit if created**

```bash
git add database/factories/
git commit -m "chore: add CourseChapterLecture factory for testing"
```

---

## Task 10: Run All Tests and Final Verification

**Files:**
- None (verification only)

- [ ] **Step 1: Run all project tests**

```bash
php artisan test
```

Expected: All tests pass with no failures.

- [ ] **Step 2: Run PHP syntax check**

```bash
php -l app/Services/VideoProgressService.php
php -l app/Models/VideoProgress.php
php -l app/Http/Controllers/API/LectureProgressApiController.php
```

Expected: No syntax errors in any file.

- [ ] **Step 3: Check for linting errors**

```bash
./vendor/bin/pint --test app/Services/VideoProgressService.php app/Models/VideoProgress.php
```

Expected: No style violations (or fix any that appear).

- [ ] **Step 4: Final commit with all changes**

```bash
git status
git add .
git commit -m "feat: complete segment-based video progress tracking implementation"
```

---

## Summary

This implementation plan adds segment-based video progress tracking to prevent users from fast-forwarding through videos. Key components:

1. **Database**: New columns for tracked segments
2. **Model**: Extended VideoProgress with segment helpers
3. **Service**: Core logic for segment tracking and seek validation
4. **API**: Updated endpoints supporting both new and legacy formats
5. **Tests**: Comprehensive unit and feature tests

After completion, the frontend can send `newly_watched_segments` arrays, and the backend will accurately track which parts of videos users have actually watched.
