# LMS Feature System — Master Task List

**Source**: `implementation_plan_v2.md`, `phase2-spec.md`, `phase2-plan.md`, `phase2-contracts.md`  
**Prerequisites**: Phase 1 complete (Subscription System — models, service, API, middleware)  
**Total Tasks**: 98 | **Est. Hours**: 65-82h  
**Format**: `- [ ] [ID] [P?] [Story] Description` | **[P]** = parallelizable | **[USn]** = user story

---

## User Stories

| ID | Story | Phase | Priority |
|----|-------|-------|----------|
| US1 | Feature Flags System | 2 | P1 |
| US2 | Free Courses & Lessons | 2 | P1 |
| US3 | Video Progress — 85% Rule | 2 | P1 |
| US4 | Lecture Attachments (admin toggle) | 2 | P2 |
| US5 | Admin Feature Flags UI | 2 | P2 |
| US6 | Affiliate Links & Commissions | 3 | P1 |
| US7 | Affiliate Withdrawals & Admin | 3 | P2 |
| US8 | Per-Country Pricing | 4 | P1 |
| US9 | Kashier Payment Gateway | 4 | P1 |
| US10 | Supervisor Roles & Permissions | 5 | P1 |
| US11 | Comment/Rating Approval | 5 | P2 |
| US12 | Marketing Pixels | 5 | P2 |
| US13 | Subscription Plan Admin CRUD (API) | 5 | P2 |
| US14 | Expiry Notifications (7d/3d/24h) | 6 | P1 |
| US15 | Auto-Renewal & Expiry Commands | 6 | P1 |
| US16 | Certificate QR Code & 100% Gate | 6 | P2 |
| US17 | Scheduled Commands Registration | 6 | P2 |

---

## Phase 1: Setup — Feature Flags Foundation

**Goal**: Admin-controllable feature toggles; all subsequent phases depend on this.  
**Independent Test**: `FeatureFlagService::isEnabled('video_progress_enforcement')` returns true; cache hit on second call.

---

- [x] T001 Create migration `create_feature_flags_table` in `database/migrations/2026_02_16_000001_create_feature_flags_table.php`

**Details**:
- Table: `feature_flags`
- Columns: `id` (bigIncrements), `key` (string, unique), `name` (string), `description` (text nullable), `is_enabled` (boolean, default false), `metadata` (json nullable), `timestamps`
- Index on `key`

**Acceptance**: Migration runs; table exists with correct schema.

---

- [x] T002 [P] Create `FeatureFlag` model in `app/Models/FeatureFlag.php`

**Details**:
- Fillable: `key`, `name`, `description`, `is_enabled`, `metadata`
- Casts: `is_enabled` => boolean, `metadata` => array
- Scope: `scopeEnabled($q)` → `$q->where('is_enabled', true)`

**Acceptance**: `FeatureFlag::where('key', 'x')->first()` works.

---

- [x] T003 Create `FeatureFlagService` in `app/Services/FeatureFlagService.php`

**Details**:
- `isEnabled(string $key, bool $default = true): bool` — Cache key `feature_flag:{key}`, TTL 3600s. If flag row missing from DB, return `$default`. This handles the edge case of uncreated flags gracefully.
- `get(string $key): ?FeatureFlag` — Same cache pattern, return model or null.
- `getAll(): Collection` — Cached as `feature_flags:all`.
- `clearCache(?string $key = null): void` — Flush specific key or all.
- Use `Cache::remember()` consistent with `CachingService` patterns.

**Acceptance**: `isEnabled('video_progress_enforcement')` returns true after seed; `isEnabled('nonexistent_flag', false)` returns false; second call hits cache.

---

- [x] T004 Create `FeatureFlagSeeder` in `database/seeders/FeatureFlagSeeder.php`

**Details**:
- Use `updateOrCreate(['key' => $key], $data)` for idempotency:
  - `lecture_attachments` — name: "ملفات مرفقة تحت الفيديو", is_enabled: **false**
  - `affiliate_system` — name: "نظام التسويق بالعمولة", is_enabled: **false**
  - `video_progress_enforcement` — name: "إلزام مشاهدة 85%", is_enabled: **true**
  - `comments_require_approval` — name: "موافقة الأدمن على التعليقات", is_enabled: **true**
  - `ratings_require_approval` — name: "موافقة الأدمن على التقييمات", is_enabled: **true**
- Register in `database/seeders/DatabaseSeeder.php`

**Acceptance**: `php artisan db:seed --class=FeatureFlagSeeder`; 5 rows exist with correct defaults.

---

- [x] T005 Run migration and seeder — `php artisan migrate && php artisan db:seed --class=FeatureFlagSeeder`

**Acceptance**: Table populated; no errors.

---

## Phase 2: Free Courses & Lessons (US2)

**Goal**: Courses/lessons can be marked free; access bypasses subscription but requires login.  
**Depends on**: Phase 1 (T005).  
**Independent Test**: Free course accessible to logged-in user without subscription; paid course returns 403.

---

- [x] T006 [P] [US2] Create migration `add_is_free_to_courses_and_lectures` in `database/migrations/2026_02_16_000002_add_is_free_to_courses_and_lectures.php`

**Details**:
- `courses` table: add `is_free` (boolean, default false), `is_free_until` (timestamp nullable)
- `course_chapter_lectures` table: add `is_free` (boolean, default false)

**Acceptance**: Migration runs; columns exist on both tables.

---

- [x] T007 [US2] Update `Course` model in `app/Models/Course/Course.php`

**Details**:
- Add `is_free`, `is_free_until` to `$fillable`
- Add casts: `'is_free' => 'boolean', 'is_free_until' => 'datetime'`
- Add helper: `isFreeNow(): bool` — returns true if `is_free === true` OR (`is_free_until !== null` && `now()->lt(is_free_until)`)

**Acceptance**: `$course->is_free` works; `isFreeNow()` returns correct values.

---

- [x] T008 [US2] Update `CourseChapterLecture` model in `app/Models/Course/CourseChapter/Lecture/CourseChapterLecture.php`

**Details**:
- Add `is_free` to `$fillable`
- Add cast: `'is_free' => 'boolean'`

**Acceptance**: `$lecture->is_free` accessible.

---

- [x] T009 [US2] Create `ContentAccessService` in `app/Services/ContentAccessService.php`

**Details**:
- `canAccessLecture(User $user, CourseChapterLecture $lecture): bool`
  1. If `$lecture->is_free` → return true
  2. Load course; if `$course->isFreeNow()` → return true
  3. If `SubscriptionService::checkAccess($user)` → return true
  4. Else → return false
- `canAccessCourse(User $user, Course $course): bool`
  1. If `$course->isFreeNow()` → return true
  2. If `SubscriptionService::checkAccess($user)` → return true
  3. Else → return false

**Acceptance**: Free lecture → true for user without subscription; paid lecture → false.

---

- [x] T010 [US2] Integrate `ContentAccessService` into lecture access flow in `app/Http/Controllers/API/CourseChapterLectureController.php` and `app/Http/Controllers/API/VideoStreamController.php`

**Details**:
- Before serving lecture content, call `ContentAccessService::canAccessLecture(auth()->user(), $lecture)`.
- If false → return 403 with message "Subscription required" using `ApiResponseService`.
- This replaces or supplements the existing `CheckActiveSubscription` middleware for these routes.

**Acceptance**: Free lecture → 200 for user without subscription; paid lecture → 403.

---

- [x] T011 [US2] Add admin UI for free flags in course/lecture edit views

**Details**:
- Course edit (`resources/views/courses/edit.blade.php`): add checkbox `is_free`, datetime input `is_free_until` (show only if is_free is unchecked)
- Lecture edit (locate in curriculum/chapter edit views): add checkbox `is_free`
- Save in corresponding controller `store`/`update` methods (`CoursesController`, `CourseChaptersController`)

**Acceptance**: Admin toggles `is_free`; value persists; affects access.

---

- [x] T012 [US2] Run migration `php artisan migrate` for free courses

**Acceptance**: Columns added; no errors.

---

## Phase 3: Video Progress — 85% Rule (US3)

**Goal**: Track watch progress; enforce 85% before next lesson unlocks; handle non-video lectures.  
**Depends on**: Phase 1 (T005).  
**Independent Test**: POST progress to 85% → next lesson accessible; POST 50% → next lesson blocked (when feature enabled).

---

- [x] T013 [P] [US3] Create migration `create_video_progress_table` in `database/migrations/2026_02_16_000003_create_video_progress_table.php`

**Details**:
- Table: `video_progress`
- Columns: `id`, `user_id` (foreignId → users), `lecture_id` (foreignId → course_chapter_lectures), `watched_seconds` (integer default 0), `total_seconds` (integer default 0), `last_position` (integer default 0), `watch_percentage` (decimal(5,2) default 0), `is_completed` (boolean default false), `completed_at` (timestamp nullable), `timestamps`
- Unique constraint: `(user_id, lecture_id)`
- Indexes: `user_id`, `lecture_id`

**Acceptance**: Migration runs; table exists with correct schema.

---

- [x] T014 [US3] Create `VideoProgress` model in `app/Models/VideoProgress.php`

**Details**:
- Fillable: `user_id`, `lecture_id`, `watched_seconds`, `total_seconds`, `last_position`, `watch_percentage`, `is_completed`, `completed_at`
- Relations: `user()` → belongsTo User, `lecture()` → belongsTo CourseChapterLecture
- Casts: `is_completed` => boolean, `completed_at` => datetime, `watch_percentage` => float
- Scopes: `scopeForUser($q, $userId)`, `scopeForLecture($q, $lectureId)`, `scopeCompleted($q)` → where is_completed true

**Acceptance**: `VideoProgress::forUser(1)->forLecture(1)->first()` works.

---

- [x] T015 [US3] Create `VideoProgressService` in `app/Services/VideoProgressService.php`

**Details**:
- `updateProgress(User $user, CourseChapterLecture $lecture, int $watchedSeconds, int $lastPosition, int $totalSeconds): VideoProgress`
  - Use `updateOrCreate(['user_id' => ..., 'lecture_id' => ...])`
  - Only increase `watched_seconds` (never decrease — anti-cheat). New value = `max($existing->watched_seconds, $watchedSeconds)`
  - Calculate `watch_percentage = (watched_seconds / total_seconds) * 100`
  - If `watch_percentage >= 85` and not previously completed → set `is_completed = true`, `completed_at = now()`

- `getProgress(User $user, CourseChapterLecture $lecture): ?array`
  - Return `['watched_seconds', 'total_seconds', 'last_position', 'watch_percentage', 'is_completed']` or null

- `canAccessNextLesson(User $user, CourseChapterLecture $lecture): bool`
  - If `!FeatureFlagService::isEnabled('video_progress_enforcement', true)` → return true
  - Get previous lesson using `getPreviousLesson($lecture)` (same chapter + cross-chapter)
  - If no previous → return true (first lesson)
  - If previous lesson has NO video (check `CourseLectureVideo` relation or `lecture_type`) → return true (non-video = auto-complete)
  - Check previous lesson progress: if `is_completed` → return true; else false

- `getCourseProgress(User $user, Course $course): float`
  - Get all lectures for the course across all chapters
  - For each lecture: if has video → check `watch_percentage`; if no video → count as 100%
  - Return average percentage (0–100)

- `getPreviousLesson(CourseChapterLecture $lecture): ?CourseChapterLecture`
  - Find lecture with lower `sort_order` in same chapter
  - If this is the first in chapter → find last lecture of previous chapter (by chapter sort_order)
  - If first lecture of first chapter → return null

**Acceptance**: First lesson → canAccess = true; non-video lecture → canAccess = true for next; 85% progress → next lesson unlocked.

---

- [x] T016 [US3] Add anti-cheat stubs in `app/Services/VideoProgressService.php`

**Details**:
- `generateProgressChallenge(User $user, CourseChapterLecture $lecture): array` — Return `['token' => Str::random(32), 'timestamp' => now()->timestamp, 'expected_position' => ...]` (placeholder)
- `validateProgressChallenge(User $user, CourseChapterLecture $lecture, array $response): bool` — Return true (stub; full implementation deferred)

**Acceptance**: Methods exist; callable without error.

---

- [x] T017 [US3] Create `LectureProgressApiController` in `app/Http/Controllers/API/LectureProgressApiController.php`

**Details**:
- `updateProgress(Request $request, int $lectureId)`:
  - Validate: `watched_seconds` (required|integer|min:0), `last_position` (required|integer|min:0), `total_seconds` (required|integer|min:1)
  - Find lecture or 404
  - Call `VideoProgressService::updateProgress()`
  - Return success with progress data via `ApiResponseService`

- `getProgress(int $lectureId)`:
  - Find lecture or 404
  - Return `VideoProgressService::getProgress()` or empty defaults

- `getCourseProgress(int $courseId)`:
  - Find course or 404
  - Return `VideoProgressService::getCourseProgress()` + per-lesson breakdown

**Acceptance**: POST returns 200 with progress; GET returns current state.

---

- [x] T018 [US3] Register video progress routes in `routes/api.php`

**Details**:
- Inside `auth:sanctum` group:
  - `POST /api/lecture/{id}/progress` → `LectureProgressApiController@updateProgress` with `throttle:10,1` (10 requests per minute per user)
  - `GET /api/lecture/{id}/progress` → `LectureProgressApiController@getProgress`
  - `GET /api/course/{id}/progress` → `LectureProgressApiController@getCourseProgress`
- Throttle standardized to **10 requests/minute** (matching API contracts)

**Acceptance**: Routes resolve; 401 when unauthenticated; 429 on 11th request within 1 min.

---

- [x] T019 [US3] Integrate sequential unlock into lecture access in `app/Http/Controllers/API/VideoStreamController.php` and `app/Http/Controllers/API/CourseChapterLectureController.php`

**Details**:
- After `ContentAccessService::canAccessLecture()` check, add:
  - If `FeatureFlagService::isEnabled('video_progress_enforcement')` AND `!VideoProgressService::canAccessNextLesson($user, $lecture)` → return 403 "Complete the previous lesson first (85% required)"
- Applies to both video streaming and lecture detail endpoints

**Acceptance**: With 85% rule on: next lesson blocked until previous at 85%; with rule off: all lessons accessible.

---

- [x] T020 [US3] Run migration `php artisan migrate` for video progress

**Acceptance**: Table created; no errors.

---

## Phase 4: Lecture Attachments (US4)

**Goal**: Admin attaches files to lectures; users see them only when feature enabled.  
**Depends on**: Phase 1 (T005).  
**Independent Test**: Upload file; feature off → empty response; enable feature → list returned.

---

- [x] T021 [P] [US4] Create migration `create_lecture_attachments_table` in `database/migrations/2026_02_16_000004_create_lecture_attachments_table.php`

**Details**:
- Table: `lecture_attachments`
- Columns: `id`, `lecture_id` (foreignId → course_chapter_lectures), `file_name` (string), `file_path` (string), `file_size` (integer unsigned), `file_type` (string 100), `sort_order` (integer default 0), `timestamps`
- Index on `lecture_id`

**Acceptance**: Migration runs; table exists.

---

- [x] T022 [US4] Create `LectureAttachment` model in `app/Models/LectureAttachment.php`

**Details**:
- Fillable: `lecture_id`, `file_name`, `file_path`, `file_size`, `file_type`, `sort_order`
- Relation: `lecture()` → belongsTo `CourseChapterLecture`
- Accessor: `getFileUrlAttribute()` → `Storage::url($this->file_path)`
- Also add `attachments()` hasMany relation on `CourseChapterLecture` model

**Acceptance**: Model works; `$lecture->attachments` returns collection.

---

- [x] T023 [US4] Create admin `LectureAttachmentController` in `app/Http/Controllers/Admin/LectureAttachmentController.php`

**Details**:
- `store(Request $request, int $lectureId)`:
  - Validate: `file` (required|file|max:51200 — 50MB)
  - Upload via `FileService` or `Storage::disk('public')->put()`
  - Create `LectureAttachment` record
  - Return JSON success with attachment data
- `destroy(int $lectureId, int $attachmentId)`:
  - Find attachment; delete file from storage; delete record
  - Return JSON success

**Acceptance**: Admin uploads file → record created; delete → file + record removed.

---

- [x] T024 [US4] Create user API endpoint for attachments in `app/Http/Controllers/API/CourseChapterLectureController.php`

**Details**:
- Add `getAttachments(int $lectureId)` method:
  - Check `FeatureFlagService::isEnabled('lecture_attachments')` → if false return `{ data: { attachments: [] } }`
  - Find lecture; return `$lecture->attachments` with `file_url`

**Acceptance**: Feature off → empty array; feature on → attachments list.

---

- [x] T025 [US4] Register attachment routes in `routes/web.php` and `routes/api.php`

**Details**:
- Admin routes (web.php): `POST /admin/lecture/{id}/attachments` → store, `DELETE /admin/lecture/{id}/attachments/{attachmentId}` → destroy
- API route (api.php): `GET /api/lecture/{id}/attachments` → `CourseChapterLectureController@getAttachments` (auth:sanctum)

**Acceptance**: Routes resolve; admin routes require auth; API route requires sanctum.

---

- [x] T026 [US4] Add admin UI for attachments in lecture edit view

**Details**:
- Locate lecture curriculum edit view (inside course chapters form area)
- Add "Attachments" section: file upload input, list of existing attachments with delete buttons
- AJAX upload/delete using routes from T025
- Section only visible when `FeatureFlagService::isEnabled('lecture_attachments')` OR always visible to admin (admin manages even when hidden from users)

**Acceptance**: Admin can upload/delete attachments via UI.

---

- [x] T027 [US4] Run migration `php artisan migrate` for lecture attachments

**Acceptance**: Table created; no errors.

---

## Phase 5: Admin Feature Flags UI (US5)

**Goal**: Admin toggles feature flags from dashboard without code changes.  
**Depends on**: Phase 1 (T005).  
**Independent Test**: Toggle `lecture_attachments` on; attachments API returns data.

---

- [x] T028 [P] [US5] Create feature flags index view in `resources/views/admin/feature-flags/index.blade.php`

**Details**:
- List all flags in a table: Name (Arabic), Key, Description, Toggle switch (is_enabled)
- Toggle sends AJAX POST to update endpoint; on success refresh row state
- Use existing admin layout (`@extends('layouts.admin')` or similar)

**Acceptance**: Page renders; all flags displayed with current state.

---

- [x] T029 [US5] Create `FeatureFlagController` in `app/Http/Controllers/Admin/FeatureFlagController.php`

**Details**:
- `index()` — return view with `FeatureFlagService::getAll()`
- `toggle(int $id)` — find flag, flip `is_enabled`, save, clear cache via `FeatureFlagService::clearCache($flag->key)`; return JSON

**Acceptance**: Toggle changes DB value; cache cleared; next `isEnabled()` reflects new value.

---

- [x] T030 [US5] Register feature flag admin routes in `routes/web.php`

**Details**:
- `GET /feature-flags` → `FeatureFlagController@index`
- `POST /feature-flags/{id}/toggle` → `FeatureFlagController@toggle`
- Apply admin middleware and permission check

**Acceptance**: Routes resolve; require admin auth.

---

- [x] T031 [US5] Add permissions for feature flags in `database/seeders/RolePermissionSeeder.php`

**Details**:
- Add `feature-flags-list`, `feature-flags-edit` permissions
- Assign to super-admin role

**Acceptance**: Permissions exist; super-admin has them.

---

- [x] T032 [US5] Add sidebar link for feature flags in `resources/views/components/sidebar.blade.php`

**Details**:
- Under Settings section: add link "Feature Flags" / "إعدادات الميزات"
- Wrap with `@can('feature-flags-list')` permission check

**Acceptance**: Link visible to admin with permission; navigates to feature flags page.

---

## Phase 6: Affiliate System — Links & Commissions (US6)

**Goal**: Referral system with one-time commissions, bi-monthly settlement, gated by feature flag.  
**Depends on**: Phase 1 (T005 — feature flags).  
**Independent Test**: Generate affiliate link; referred user subscribes; commission created with correct amount and available_date.

---

- [x] T033 [US6] Create migration `create_affiliate_tables` in `database/migrations/2026_02_16_100001_create_affiliate_tables.php`

**Details**:
- Table `affiliate_links`: `id`, `user_id` (foreignId), `code` (string unique), `total_clicks` (integer default 0), `total_conversions` (integer default 0), `is_active` (boolean default true), `timestamps`
- Table `affiliate_commissions`: `id`, `affiliate_id` (foreignId → users), `referred_user_id` (foreignId → users), `subscription_id` (foreignId → subscriptions), `plan_id` (foreignId → subscription_plans), `amount` (decimal 10,2), `commission_rate` (decimal 5,2), `status` (enum: pending/available/withdrawn/cancelled, default pending), `earned_date` (date), `available_date` (date), `settlement_period_start` (date), `settlement_period_end` (date), `withdrawn_at` (timestamp nullable), `timestamps`
- Table `affiliate_withdrawals`: `id`, `affiliate_id` (foreignId → users), `amount` (decimal 10,2), `commission_ids` (json), `status` (enum: pending/processing/completed/failed/rejected, default pending), `requested_at` (timestamp), `processed_at` (timestamp nullable), `processed_by` (foreignId nullable → users), `rejection_reason` (text nullable), `timestamps`
- Indexes: affiliate_commissions(affiliate_id, status), affiliate_commissions(available_date)

**Acceptance**: All 3 tables created with correct schema.

---

- [x] T034 [US6] Add `commission_rate` to subscription plans in `database/migrations/2026_02_16_100002_add_commission_rate_to_subscription_plans.php`

**Details**:
- Check if migration `2026_02_15_000001_add_commission_rate_to_subscription_plans_table.php` already exists and has the column. If yes, skip. Otherwise add `commission_rate` (decimal 5,2, default 0) to `subscription_plans` table.
- Update `SubscriptionPlan` model (`app/Models/SubscriptionPlan.php`): add `commission_rate` to fillable + cast as float.

**Acceptance**: Column exists; model fillable updated.

---

- [x] T035 [US6] Create affiliate models in `app/Models/`

**Details**:
- `app/Models/AffiliateLink.php`: fillable (user_id, code, total_clicks, total_conversions, is_active); relations (user); casts (is_active => boolean)
- `app/Models/AffiliateCommission.php`: fillable (all columns); relations (affiliate → User, referredUser → User, subscription, plan → SubscriptionPlan); casts (amount => float, commission_rate => float, earned_date => date, available_date => date, withdrawn_at => datetime); scopes (pending, available, forAffiliate)
- `app/Models/AffiliateWithdrawal.php`: fillable (all columns); relations (affiliate → User, processedBy → User); casts (amount => float, commission_ids => array, requested_at => datetime, processed_at => datetime)

**Acceptance**: All 3 models work with correct relations.

---

- [x] T036 [US6] Create `AffiliateService` in `app/Services/AffiliateService.php`

**Details**:
- `isEnabled(): bool` — `FeatureFlagService::isEnabled('affiliate_system', false)`
- `generateAffiliateLink(User $user): AffiliateLink` — Create unique code (e.g. `Str::random(8)`); return existing if user already has one
- `trackClick(string $code): void` — Increment `total_clicks`; store referral code in session/cookie
- `processReferral(User $referredUser, Subscription $subscription): ?AffiliateCommission`
  - Check if system enabled
  - Check if referred user was referred (has referral code in session/cookie or `referred_by` field)
  - Check if referred user already has a commission recorded (one-time only)
  - Calculate: `$plan->commission_rate / 100 * $subscription->payment_amount`
  - Calculate `available_date` using `calculateAvailableDate($earnedDate)`
  - Create `AffiliateCommission` with status `pending`
  - Increment `total_conversions` on affiliate link
- `calculateAvailableDate(Carbon $earnedDate): Carbon`
  - If day 1-15 → return day 28 of same month
  - If day 16-end → return day 15 of next month
- `getAvailableBalance(User $user): float` — Sum of commissions where status = `available`
- `getPendingBalance(User $user): float` — Sum of commissions where status = `pending`
- `getMinimumWithdrawalAmount(): float` — From settings or default 500

**Acceptance**: Referral creates commission with correct amount and available_date; bi-monthly dates correct.

---

- [x] T037 [US6] Add affiliate settings to system settings

**Details**:
- Add to `DefaultSettingService` or settings migration: `affiliate_min_withdrawal` (default 500), `affiliate_system_enabled` (synced with feature flag)
- Alternatively store in `feature_flags.metadata` for the `affiliate_system` flag
- `AffiliateService::getMinimumWithdrawalAmount()` reads from settings

**Acceptance**: Setting stored and retrievable.

---

- [x] T038 [US6] Create `AffiliateApiController` in `app/Http/Controllers/API/AffiliateApiController.php`

**Details**:
- All methods check `AffiliateService::isEnabled()` first → if false return 404
- `status()` — Return `{ enabled: true/false }`
- `getMyLink()` — Return or generate affiliate link for auth user
- `getStats()` — Return `{ available_balance, pending_balance, total_conversions, total_clicks }`
- `getCommissions(Request $request)` — Paginated list with optional status filter
- `trackReferral(string $code)` — Call `AffiliateService::trackClick()`, store in cookie, redirect

**Acceptance**: When disabled → 404; when enabled → correct data returned.

---

- [x] T039 [US6] Register affiliate API routes in `routes/api.php`

**Details**:
- Public: `GET /api/affiliate/status`, `GET /api/ref/{code}` → trackReferral
- Auth (sanctum): `GET /api/affiliate/my-link`, `GET /api/affiliate/stats`, `GET /api/affiliate/commissions`
- All wrapped in middleware that checks feature flag (or check in controller)

**Acceptance**: Routes resolve; feature flag respected.

---

- [x] T040 [US6] Hook referral processing into subscription creation in `app/Services/SubscriptionService.php`

**Details**:
- In `SubscriptionService::create()` or `subscribe()` method, after successful subscription:
  - Call `AffiliateService::processReferral($user, $subscription)`
  - Only triggers if affiliate system enabled and user was referred

**Acceptance**: New subscription from referred user → commission created.

---

- [x] T041 [US6] Run affiliate migrations `php artisan migrate`

**Acceptance**: All affiliate tables created.

---

## Phase 7: Affiliate Withdrawals & Admin (US7)

**Goal**: Affiliates can request withdrawals; admin approves/rejects; commissions auto-release on schedule.  
**Depends on**: Phase 6 (T041).

---

- [x] T042 [US7] Add withdrawal methods to `AffiliateService` in `app/Services/AffiliateService.php`

**Details**:
- `requestWithdrawal(User $user, float $amount): AffiliateWithdrawal`
  - Validate: system enabled, amount >= min withdrawal, available balance >= amount
  - Create withdrawal record with status `pending`
  - Mark included commissions as `withdrawn`
  - Return withdrawal
- `getWithdrawals(User $user): Collection` — Paginated withdrawal history
- `processWithdrawal(AffiliateWithdrawal $withdrawal, User $admin): void` — Set status `completed`, processed_at, processed_by
- `rejectWithdrawal(AffiliateWithdrawal $withdrawal, string $reason, User $admin): void` — Set status `rejected`, rejection_reason; revert commission statuses back to `available`
- `releaseCommissions(): int` — Query commissions where status=pending AND available_date <= today; update to `available`; return count

**Acceptance**: Withdrawal flow works; rejection reverts commissions; release changes pending → available.

---

- [x] T043 [US7] Add withdrawal endpoint to `AffiliateApiController` in `app/Http/Controllers/API/AffiliateApiController.php`

**Details**:
- `requestWithdrawal(Request $request)` — Validate `amount` (required|numeric|min:1); call service; return result
- `getWithdrawals()` — Return paginated withdrawal list

**Acceptance**: POST `/api/affiliate/withdraw` creates withdrawal.

---

- [x] T044 [US7] Register withdrawal routes in `routes/api.php`

**Details**:
- Auth (sanctum): `POST /api/affiliate/withdraw`, `GET /api/affiliate/withdrawals`

**Acceptance**: Routes resolve.

---

- [x] T045 [US7] Create admin affiliate controller in `app/Http/Controllers/Admin/AffiliateController.php`

**Details**:
- `settings()` — Return affiliate settings (enabled, min_withdrawal)
- `updateSettings(Request $request)` — Update settings + toggle feature flag
- `pendingWithdrawals()` — List pending withdrawal requests
- `approveWithdrawal(int $id)` — Call `AffiliateService::processWithdrawal()`
- `rejectWithdrawal(Request $request, int $id)` — Validate reason; call `AffiliateService::rejectWithdrawal()`
- `allCommissions()` — Paginated all commissions with filters
- `stats()` — System-wide stats (total commissions, total payouts, pending amount)

**Acceptance**: Admin can view/approve/reject withdrawals.

---

- [x] T046 [US7] Register admin affiliate routes in `routes/web.php` or `routes/api.php`

**Details**:
- Admin routes: `GET /api/admin/affiliate/settings`, `PUT /api/admin/affiliate/settings`, `GET /api/admin/affiliate/withdrawals/pending`, `POST /api/admin/affiliate/withdrawals/{id}/approve`, `POST /api/admin/affiliate/withdrawals/{id}/reject`, `GET /api/admin/affiliate/commissions`, `GET /api/admin/affiliate/stats`

**Acceptance**: All admin routes functional.

---

- [x] T047 [US7] Create `ReleaseAffiliateCommissions` artisan command in `app/Console/Commands/ReleaseAffiliateCommissions.php`

**Details**:
- Signature: `affiliate:release-commissions`
- Logic: Call `AffiliateService::releaseCommissions()`
- Output: "Released {count} commissions"
- Run daily via scheduler

**Acceptance**: Command releases pending commissions whose available_date has passed.

---

## Phase 8: Per-Country Pricing (US8)

**Goal**: Udemy-style per-country pricing; users see local currency; billing in EGP.  
**Depends on**: Phase 1 complete.

---

- [x] T048 [P] [US8] Create migration `create_pricing_tables` in `database/migrations/2026_02_16_200001_create_pricing_tables.php`

**Details**:
- Table `supported_currencies`: `id`, `country_code` (string 2, unique), `country_name` (string), `currency_code` (string 3), `currency_symbol` (string 10), `exchange_rate_to_egp` (decimal 10,4), `is_active` (boolean default true), `timestamps`
- Table `subscription_plan_prices`: `id`, `plan_id` (foreignId → subscription_plans), `country_code` (string 2), `currency_code` (string 3), `price` (decimal 10,2), `timestamps`
- Unique constraint on `subscription_plan_prices`: `(plan_id, country_code)`

**Acceptance**: Tables created with correct schema.

---

- [x] T049 [US8] Create pricing models

**Details**:
- `app/Models/SupportedCurrency.php`: fillable (country_code, country_name, currency_code, currency_symbol, exchange_rate_to_egp, is_active); casts (is_active => boolean, exchange_rate_to_egp => float)
- `app/Models/SubscriptionPlanPrice.php`: fillable (plan_id, country_code, currency_code, price); relations (plan → SubscriptionPlan); casts (price => float)
- Add `countryPrices()` hasMany to `SubscriptionPlan` model

**Acceptance**: Models and relations work.

---

- [x] T050 [US8] Create `PricingService` in `app/Services/PricingService.php` (or extend existing `PricingCalculationService`)

**Details**:
- `getPriceForCountry(SubscriptionPlan $plan, string $countryCode): array`
  - Look up `SubscriptionPlanPrice` for plan + country
  - If exists → return `{ price, currency_code, currency_symbol }`
  - If not → return base EGP price with EGP symbol
- `detectUserCountry(Request $request): string` — Use existing `GeoLocationService` to detect country from IP
- `convertToEgp(float $amount, string $currencyCode): float` — Use `SupportedCurrency::exchange_rate_to_egp`

**Acceptance**: User from SA sees SAR price; from unknown country sees EGP.

---

- [x] T051 [US8] Modify subscription plans API to return localized prices in `app/Http/Controllers/API/SubscriptionApiController.php`

**Details**:
- In `getPlans()`: detect country, return plans with localized price using `PricingService`
- Response includes `display_price`, `display_currency`, `display_symbol` per plan

**Acceptance**: API returns localized prices based on user's country.

---

- [x] T052 [US8] Create `SupportedCurrencySeeder` in `database/seeders/SupportedCurrencySeeder.php`

**Details**:
- Seed initial currencies: EG (EGP, ج.م), SA (SAR, ﷼), AE (AED, د.إ), US (USD, $), and a few more
- Exchange rates approximate (admin can update later)

**Acceptance**: Currencies seeded.

---

- [x] T053 [US8] Add admin UI for country pricing on subscription plan edit

**Details**:
- On plan edit page (`resources/views/admin/subscription-plans/edit.blade.php`): add section "Country Prices"
- Table: country, currency, price input for each active currency
- Save via AJAX or form submit to new route

**Acceptance**: Admin sets per-country prices; values persist.

---

- [x] T054 [US8] Run pricing migrations and seeder

**Acceptance**: Tables + seed data exist.

---

## Phase 9: Kashier Payment Gateway (US9)

**Goal**: Integrate Kashier as primary payment gateway for subscriptions.  
**Depends on**: Phase 8 (pricing).

---

- [x] T055 [US9] Create `KashierCheckoutService` in `app/Services/Payment/KashierCheckoutService.php`

**Details**:
- Implements `PaymentGatewayContract` or `PaymentInterface` (use existing interface pattern from `StripeCheckoutService`/`RazorpayCheckoutService`)
- `createCheckoutSession(SubscriptionPlan $plan, User $user, float $amount): array` — Call Kashier API to initiate payment; return redirect URL or iframe data
- `verifyPayment(array $data): bool` — Verify callback/webhook signature
- `getPaymentStatus(string $transactionId): string` — Query Kashier API
- Kashier credentials from settings: `kashier_merchant_id`, `kashier_api_key`, `kashier_webhook_secret`, `kashier_mode` (test/live)

**Acceptance**: Checkout session created; webhook verified.

---

- [x] T056 [US9] Register Kashier in `PaymentFactory` in `app/Services/Payment/PaymentFactory.php`

**Details**:
- Add `case 'kashier': return new KashierCheckoutService();` (or resolve from container)

**Acceptance**: `PaymentFactory::create('kashier')` returns KashierCheckoutService.

---

- [x] T057 [US9] Create Kashier webhook handler in `app/Http/Controllers/KashierController.php`

**Details**:
- `handleWebhook(Request $request)` — Verify signature, process payment status update
- On success: activate subscription, create `SubscriptionPayment` record
- Route: `POST /webhooks/kashier` (excluded from CSRF)

**Acceptance**: Webhook processes payment correctly; subscription activated.

---

- [x] T058 [US9] Add Kashier settings to admin in `app/Http/Controllers/SettingsController.php`

**Details**:
- Add fields: `kashier_merchant_id`, `kashier_api_key`, `kashier_webhook_secret`, `kashier_mode`
- Add to settings view (Payment Settings section)

**Acceptance**: Admin can configure Kashier credentials.

---

- [x] T059 [US9] Modify subscription flow to use Kashier in `app/Services/SubscriptionService.php`

**Details**:
- In `subscribe()`: check wallet balance first → deduct from wallet → if remaining amount > 0 → create Kashier checkout for remainder
- Add `walletAndGatewayPayment(User $user, SubscriptionPlan $plan, float $totalAmount): array` logic

**Acceptance**: Full payment via wallet or split (wallet + Kashier) works.

---

- [x] T060 [US9] Register Kashier webhook route in `routes/web.php`

**Details**:
- `POST /webhooks/kashier` → `KashierController@handleWebhook`
- Add to CSRF exception list in middleware

**Acceptance**: Route accessible; not blocked by CSRF.

---

## Phase 10: Supervisor Roles & Permissions (US10)

**Goal**: Replace "Instructor" with "Supervisor"; implement granular permissions via Spatie.  
**Depends on**: None (independent).

---

- [x] T061 [P] [US10] Update permission seeder in `database/seeders/RolePermissionSeeder.php`

**Details**:
- Add new permissions: `manage_accounts`, `manage_courses`, `upload_courses`, `manage_subscriptions`, `manage_finances`, `approve_comments`, `approve_ratings`, `manage_affiliates`, `manage_settings`, `manage_plans`, `view_reports`
- Create "Supervisor" (مشرف) role
- Keep existing roles intact for backward compatibility

**Acceptance**: Permissions seeded; Supervisor role exists.

---

- [x] T062 [US10] Rename "Instructor" display labels to "Supervisor" (مشرف)

**Details**:
- Update UI labels in views (NOT database columns):
  - `resources/views/components/sidebar.blade.php`: change "Instructors" → "Supervisors" / "المشرفين"
  - `resources/views/instructor/` views: update display text
  - `app/Http/Controllers/InstructorController.php`: update page titles
- Keep DB column names and route names for backward compatibility

**Acceptance**: UI shows "Supervisor" everywhere; no DB changes.

---

- [x] T063 [US10] Create admin UI for role/permission assignment

**Details**:
- Update staff/user edit view (`resources/views/Roles/` or `resources/views/admin/users/`)
- Add permission checkboxes when editing a supervisor
- Save via existing Spatie `assignRole()` / `givePermissionTo()` methods
- Controller: update `StaffController` or `RoleController` (already exists at `app/Http/Controllers/RoleController.php`)

**Acceptance**: Admin can assign granular permissions to supervisors.

---

- [x] T064 [US10] Add permission checks to admin controllers

**Details**:
- Add `$this->middleware('permission:manage_courses')` (or `@can` in views) to:
  - `CoursesController` → `manage_courses`
  - `UserController` → `manage_accounts`
  - `WalletController` → `manage_finances`
  - `RatingController` → `approve_ratings`
  - `SubscriptionPlanController` → `manage_plans`
  - `SettingsController` → `manage_settings`

**Acceptance**: Supervisor without permission gets 403; with permission gets 200.

---

- [x] T065 [US10] Run permissions seeder `php artisan db:seed --class=RolePermissionSeeder`

**Acceptance**: New permissions and Supervisor role exist.

---

## Phase 11: Comment/Rating Approval System (US11)

**Goal**: Admin must approve comments/ratings before they're publicly visible.  
**Depends on**: Feature flags (T005).

---

- [x] T066 [P] [US11] Create migration `add_approval_fields` in `database/migrations/2026_02_16_300001_add_approval_fields_to_ratings_and_discussions.php`

**Details**:
- `ratings` table: add `status` (enum: pending/approved/rejected, default pending), `reviewed_by` (foreignId nullable), `reviewed_at` (timestamp nullable)
- `course_discussions` table: add `status` (enum: pending/approved/rejected, default pending), `reviewed_by` (foreignId nullable), `reviewed_at` (timestamp nullable)

**Acceptance**: Columns added to both tables.

---

- [x] T067 [US11] Update `Rating` model in `app/Models/Rating.php`

**Details**:
- Add `status`, `reviewed_by`, `reviewed_at` to fillable
- Casts: `reviewed_at` => datetime
- Scope: `scopeApproved($q)` → `where('status', 'approved')`
- Scope: `scopePending($q)` → `where('status', 'pending')`

**Acceptance**: `Rating::approved()->get()` returns only approved ratings.

---

- [x] T068 [US11] Update `CourseDiscussion` model in `app/Models/CourseDiscussion.php`

**Details**:
- Same changes as Rating: add `status`, `reviewed_by`, `reviewed_at`; scopes for approved/pending

**Acceptance**: `CourseDiscussion::approved()->get()` works.

---

- [x] T069 [US11] Modify public API to filter by approval status

**Details**:
- `app/Http/Controllers/API/RatingApiController.php`: in listing endpoints, add `->when(FeatureFlagService::isEnabled('ratings_require_approval'), fn($q) => $q->approved())`
- `app/Http/Controllers/API/CourseDiscussionApiController.php`: same pattern with `comments_require_approval` flag
- New submissions default to `status = 'pending'`

**Acceptance**: Only approved items visible in public API when flag enabled.

---

- [x] T070 [US11] Create admin approval controller in `app/Http/Controllers/Admin/ApprovalController.php`

**Details**:
- `pendingRatings()` — List ratings where status = pending
- `approveRating(int $id)` — Set status approved, reviewed_by, reviewed_at
- `rejectRating(int $id)` — Set status rejected
- `pendingComments()` — List discussions where status = pending
- `approveComment(int $id)` — Set status approved
- `rejectComment(int $id)` — Set status rejected

**Acceptance**: Admin can approve/reject; status persists.

---

- [x] T071 [US11] Register approval admin routes

**Details**:
- Routes in `routes/web.php` or `routes/api.php`:
  - `GET /api/admin/reviews/pending`, `POST /api/admin/reviews/{id}/approve`, `POST /api/admin/reviews/{id}/reject`
  - `GET /api/admin/comments/pending`, `POST /api/admin/comments/{id}/approve`, `POST /api/admin/comments/{id}/reject`
- Permission: `approve_comments`, `approve_ratings`

**Acceptance**: Routes resolve with correct permissions.

---

- [x] T072 [US11] Create admin approval UI (optional — Blade views)

**Details**:
- Create `resources/views/admin/approvals/index.blade.php`: tabs for Ratings / Comments
- Each tab shows pending items with Approve / Reject buttons
- Add sidebar link under "Content Management"

**Acceptance**: Admin sees pending items; can approve/reject via UI.

---

- [ ] T073 [US11] Run approval migration `php artisan migrate`

**Acceptance**: Columns added; no errors.

---

## Phase 12: Marketing Pixels (US12)

**Goal**: Admin links marketing platforms via dashboard; frontend dynamically injects scripts.  
**Depends on**: None (independent).

---

- [x] T074 [P] [US12] Create migration `create_marketing_pixels_table` in `database/migrations/2026_02_16_400001_create_marketing_pixels_table.php`

**Details**:
- Table: `marketing_pixels`
- Columns: `id`, `platform` (enum: hotjar/microsoft_clarity/google_tag_manager/facebook/tiktok/snapchat/instagram), `pixel_id` (string), `is_active` (boolean default false), `additional_config` (json nullable), `timestamps`
- Unique on `platform`

**Acceptance**: Table created.

---

- [x] T075 [US12] Create `MarketingPixel` model in `app/Models/MarketingPixel.php`

**Details**:
- Fillable: `platform`, `pixel_id`, `is_active`, `additional_config`
- Casts: `is_active` => boolean, `additional_config` => array
- Scope: `scopeActive($q)` → `where('is_active', true)`

**Acceptance**: Model works.

---

- [x] T076 [US12] Create admin `MarketingPixelController` in `app/Http/Controllers/Admin/MarketingPixelController.php`

**Details**:
- `index()` — List all pixels
- `store(Request $request)` — Validate platform (required|in:...), pixel_id (required|string); updateOrCreate by platform
- `destroy(int $id)` — Delete pixel

**Acceptance**: Admin can add/update/remove pixels.

---

- [x] T077 [US12] Create public API for active pixels in `app/Http/Controllers/API/MarketingPixelApiController.php`

**Details**:
- `getActivePixels()` — Return `MarketingPixel::active()->get(['platform', 'pixel_id', 'additional_config'])` (no auth required)
- Cache for 60 seconds to avoid DB hits on every page load

**Acceptance**: `GET /api/marketing-pixels/active` returns active pixels.

---

- [x] T078 [US12] Register marketing pixel routes

**Details**:
- Admin (web.php): `GET /marketing-pixels`, `POST /marketing-pixels`, `DELETE /marketing-pixels/{id}`
- Public API (api.php): `GET /api/marketing-pixels/active`

**Acceptance**: All routes resolve.

---

- [x] T079 [US12] Create admin marketing pixels view in `resources/views/admin/marketing-pixels/index.blade.php`

**Details**:
- Table: Platform name, Pixel ID, Status toggle, Delete button
- Form to add new pixel: dropdown for platform, text input for pixel_id
- Add sidebar link

**Acceptance**: Admin manages pixels from dashboard.

---

- [ ] T080 [US12] Run marketing pixels migration `php artisan migrate`

**Acceptance**: Table created.

---

## Phase 13: Subscription Plan Admin CRUD — API (US13)

**Goal**: Full admin API for managing subscription plans including country-specific pricing.  
**Depends on**: Phase 8 (pricing tables).

---

- [ ] T081 [US13] Extend `SubscriptionPlanController` (admin) for full CRUD API in `app/Http/Controllers/Admin/SubscriptionPlanController.php`

**Details**:
- Verify existing methods: `index`, `create`, `store`, `edit`, `update`, `destroy`
- Add `toggle(int $id)` — flip `is_active`
- Add `updateSortOrder(Request $request, int $id)` — update `sort_order`
- Add `setCountryPrices(Request $request, int $id)` — upsert `SubscriptionPlanPrice` records

**Acceptance**: All admin CRUD operations work; toggle activates/deactivates.

---

- [ ] T082 [US13] Register admin plan management routes

**Details**:
- If not already registered: `POST /admin/subscription-plans/{id}/toggle`, `PUT /admin/subscription-plans/{id}/sort`, `POST /admin/subscription-plans/{id}/prices`
- API equivalents: `POST /api/admin/subscription-plans/{id}/toggle`, etc.

**Acceptance**: Routes resolve.

---

## Phase 14: Expiry Notifications (US14)

**Goal**: Send push (FCM) + email notifications at 7 days, 3 days, and 24 hours before subscription expiry.  
**Depends on**: Phase 1 (subscriptions with notification flags).

---

- [ ] T083 [P] [US14] Create `SendSubscriptionExpiryNotifications` command in `app/Console/Commands/SendSubscriptionExpiryNotifications.php`

**Details**:
- Signature: `subscriptions:send-expiry-notifications`
- Logic:
  1. Query active subscriptions where `ends_at IS NOT NULL`
  2. For each threshold (7d, 3d, 1d):
     - Find subscriptions where `ends_at` is within threshold AND `notified_X_days` is false
     - Send push notification via `NotificationService` (use existing FCM logic from `UserFcmToken`)
     - Send email (use Laravel Mail or existing email pattern)
     - Set `notified_X_days = true`
  3. Skip lifetime subscriptions (ends_at is null)
- Push titles (Arabic): 7d: "اشتراكك ينتهي خلال 7 أيام", 3d: "اشتراكك ينتهي خلال 3 أيام", 1d: "اشتراكك ينتهي غداً!"

**Acceptance**: Running command sends correct notifications; flags prevent duplicates.

---

- [ ] T084 [US14] Create expiry notification email templates

**Details**:
- `resources/views/emails/subscription-expiry-7days.blade.php`
- `resources/views/emails/subscription-expiry-3days.blade.php`
- `resources/views/emails/subscription-expiry-24hours.blade.php`
- Each with user name, plan name, expiry date, renewal link
- Use existing email layout if available

**Acceptance**: Emails render correctly with dynamic data.

---

- [ ] T085 [US14] Create Laravel Mailable classes

**Details**:
- `app/Mail/SubscriptionExpiry7Days.php`
- `app/Mail/SubscriptionExpiry3Days.php`
- `app/Mail/SubscriptionExpiry24Hours.php`
- Each accepts User + Subscription; renders corresponding Blade template

**Acceptance**: Mailables instantiate and render without error.

---

## Phase 15: Auto-Renewal & Expiry Commands (US15)

**Goal**: Auto-renew expired subscriptions; mark non-renewed as expired.  
**Depends on**: Phase 9 (Kashier integration for payment).

---

- [ ] T086 [US15] Create `HandleExpiredSubscriptions` command in `app/Console/Commands/HandleExpiredSubscriptions.php`

**Details**:
- Signature: `subscriptions:handle-expired`
- Logic:
  1. Find active subscriptions where `ends_at < now()` AND `ends_at IS NOT NULL`
  2. For each:
     - If `auto_renew = true`: attempt renewal via `SubscriptionService::renewWithPayment()` (wallet first, then Kashier)
     - If renewal fails or `auto_renew = false`: set `status = 'expired'`
  3. Log results

**Acceptance**: Expired with auto_renew → renewed; without → marked expired.

---

## Phase 16: Certificate QR Code & 100% Gate (US16)

**Goal**: Certificates include QR code; only generated at 100% course completion.  
**Depends on**: Phase 3 (video progress tracking).

---

- [ ] T087 [US16] Install QR code package

**Details**:
- `composer require simplesoftwareio/simple-qrcode`

**Acceptance**: Package installed.

---

- [ ] T088 [US16] Modify `CertificateService` in `app/Services/CertificateService.php`

**Details**:
- Before generating certificate, check:
  - `VideoProgressService::getCourseProgress($user, $course) === 100.0`
  - If not 100% → throw exception or return error with progress details
- Add QR code generation:
  - QR data: `{config('app.url')}/certificate/verify/{certificate_number}`
  - Generate QR PNG using `QrCode::format('png')->size(150)->generate($url)`
  - Embed QR in PDF template (modify existing PDF template)

**Acceptance**: Certificate rejected if < 100%; QR code visible on generated certificate.

---

- [ ] T089 [US16] Create certificate verification endpoint

**Details**:
- Route: `GET /certificate/verify/{number}` (public, no auth)
- Controller method (in `CertificateController`): look up certificate by number, return verification page with course name, student name, completion date
- View: `resources/views/certificates/verify.blade.php`

**Acceptance**: QR code URL shows verification page.

---

## Phase 17: Scheduled Commands & Polish (US17)

**Goal**: Register all commands in scheduler; final integration checks.  
**Depends on**: All previous phases.

---

- [ ] T090 [US17] Register commands in scheduler in `routes/console.php` or `app/Console/Kernel.php`

**Details**:
- `$schedule->command('subscriptions:send-expiry-notifications')->daily();`
- `$schedule->command('subscriptions:handle-expired')->daily();`
- `$schedule->command('affiliate:release-commissions')->daily();`
- Verify Kernel or console.php pattern used in this project

**Acceptance**: `php artisan schedule:list` shows all 3 commands.

---

- [ ] T091 [US17] Add `referred_by` field to users table

**Details**:
- Create migration: `add_referred_by_to_users_table` — add `referred_by` (foreignId nullable → users) to `users` table
- Update `User` model: add to fillable; add `referrer()` belongsTo relation
- Used during registration to persist which affiliate referred the user

**Acceptance**: Column exists; model updated.

---

- [ ] T092 [P] [US17] Add user registration hook for affiliate tracking

**Details**:
- In registration flow (locate in `AuthController` or API auth): if referral cookie/session exists, save `referred_by` user ID on new user record
- This ensures referral tracking persists beyond the session

**Acceptance**: User registered with referral code → `referred_by` populated.

---

- [ ] T093 [US17] Backfill existing ratings/comments with approved status

**Details**:
- Create migration or command: `UPDATE ratings SET status = 'approved' WHERE status IS NULL`
- Same for `course_discussions`
- Ensures existing content remains visible after approval system goes live

**Acceptance**: All pre-existing ratings/comments have status = 'approved'.

---

- [ ] T094 [US17] Add commission_rate field to subscription plan admin forms

**Details**:
- Update `resources/views/admin/subscription-plans/create.blade.php` and `edit.blade.php`
- Add input field: "Commission Rate (%)" with numeric validation
- Save in `SubscriptionPlanController`

**Acceptance**: Admin can set commission rate per plan.

---

- [ ] T095 [US17] Wallet top-up via Kashier

**Details**:
- Add endpoint `POST /api/wallet/top-up` in `WalletApiController`
- User provides amount; create Kashier checkout session for wallet deposit
- On webhook callback: credit wallet via `WalletService`
- Route in `routes/api.php`

**Acceptance**: User can top up wallet via Kashier.

---

- [ ] T096 [US17] Admin currencies management UI

**Details**:
- Create `resources/views/admin/currencies/index.blade.php`: list supported currencies
- Allow admin to update exchange rates, add/remove countries
- Controller in `app/Http/Controllers/Admin/CurrencyController.php`
- Sidebar link

**Acceptance**: Admin manages supported currencies.

---

- [x] T097 [US17] Add marketing pixels sidebar link in `resources/views/components/sidebar.blade.php`

**Details**:
- Add link under Settings or Marketing section
- Permission check: `@can('manage_settings')`

**Acceptance**: Sidebar link visible to admin.

---

- [ ] T098 [US17] Final integration verification

**Details**:
- Verify all feature flags work end-to-end
- Verify subscription → affiliate commission → withdrawal flow
- Verify per-country pricing → Kashier payment → subscription activation
- Verify 85% progress → next lesson unlock → certificate generation
- Verify notification commands run correctly

**Acceptance**: Full system integration test passes.

---

## Dependencies & Execution Order

```
FOUNDATION:
T001 → T002 → T003 → T004 → T005

AFTER T005, the following can run in PARALLEL:
├── US2: T006 → T007 → T008 → T009 → T010 → T011 → T012
├── US3: T013 → T014 → T015 → T016 → T017 → T018 → T019 → T020
├── US4: T021 → T022 → T023 → T024 → T025 → T026 → T027
├── US5: T028 → T029 → T030 → T031 → T032
├── US10: T061 → T062 → T063 → T064 → T065
├── US11: T066 → T067 → T068 → T069 → T070 → T071 → T072 → T073
├── US12: T074 → T075 → T076 → T077 → T078 → T079 → T080
└── US14: T083 → T084 → T085

SEQUENTIAL CHAINS:
T005 → T033 → T034 → T035 → T036 → T037 → T038 → T039 → T040 → T041 (US6)
T041 → T042 → T043 → T044 → T045 → T046 → T047 (US7)
T005 → T048 → T049 → T050 → T051 → T052 → T053 → T054 (US8)
T054 → T055 → T056 → T057 → T058 → T059 → T060 (US9)
T054 → T081 → T082 (US13)
T059 → T086 (US15)
T020 → T087 → T088 → T089 (US16)
ALL → T090 → T091 → T092 → T093 → T094 → T095 → T096 → T097 → T098 (US17)
```

---

## Implementation Strategy

| Priority | Phase | Description | MVP? |
|----------|-------|-------------|------|
| 1 | Phase 1 | Feature Flags Foundation | ✅ Required |
| 2 | Phase 2 | Free Courses & Lessons | ✅ Required |
| 3 | Phase 3 | Video Progress (85% Rule) | ✅ Required |
| 4 | Phase 4 | Lecture Attachments | ✅ Required |
| 5 | Phase 5 | Admin Feature Flags UI | ✅ Required |
| 6 | Phase 6-7 | Affiliate System | ✅ Required |
| 7 | Phase 8 | Per-Country Pricing | ✅ Required |
| 8 | Phase 9 | Kashier Payment | ✅ Required |
| 9 | Phase 10 | Supervisor Roles | ✅ Required |
| 10 | Phase 11 | Comment/Rating Approval | ✅ Required |
| 11 | Phase 12 | Marketing Pixels | ✅ Required |
| 12 | Phase 13 | Admin Plan CRUD (API) | ✅ Required |
| 13 | Phase 14-15 | Notifications & Auto-Renewal | ✅ Required |
| 14 | Phase 16 | Certificate QR & 100% Gate | ✅ Required |
| 15 | Phase 17 | Polish & Integration | ✅ Required |

**Suggested MVP Scope**: Phases 1-5 (Feature Flags + Free Courses + Video Progress + Attachments + Admin UI) = ~18-22h

---

## Summary

| Metric | Value |
|--------|-------|
| **Total tasks** | 98 |
| **Phases** | 17 |
| **User stories** | 17 |
| **Parallel opportunities** | 8 independent branches after T005 |
| **Estimated hours** | 65-82h |
| **Format validated** | ✅ All tasks have checkbox, ID, labels, file paths |

### Tasks Per User Story

| Story | Tasks | Range |
|-------|-------|-------|
| US1 (Feature Flags) | 5 | T001-T005 |
| US2 (Free Courses) | 7 | T006-T012 |
| US3 (Video Progress) | 8 | T013-T020 |
| US4 (Lecture Attachments) | 7 | T021-T027 |
| US5 (Admin Feature Flags) | 5 | T028-T032 |
| US6 (Affiliate Links) | 9 | T033-T041 |
| US7 (Affiliate Admin) | 6 | T042-T047 |
| US8 (Country Pricing) | 7 | T048-T054 |
| US9 (Kashier) | 6 | T055-T060 |
| US10 (Supervisor Roles) | 5 | T061-T065 |
| US11 (Approval System) | 8 | T066-T073 |
| US12 (Marketing Pixels) | 7 | T074-T080 |
| US13 (Plan Admin CRUD) | 2 | T081-T082 |
| US14 (Expiry Notifications) | 3 | T083-T085 |
| US15 (Auto-Renewal) | 1 | T086 |
| US16 (Certificate QR) | 3 | T087-T089 |
| US17 (Polish & Integration) | 9 | T090-T098 |

### Analysis Fixes Incorporated

| Issue | Fix Applied |
|-------|------------|
| Throttle rate inconsistency | Standardized to **10 requests/minute** (T018) |
| Controller underspecification | Explicitly named `VideoStreamController` and `CourseChapterLectureController` (T010, T019) |
| Non-video lecture handling | Added explicit logic in `VideoProgressService::canAccessNextLesson()` (T015) |
| Feature flag missing defaults | Added `$default` parameter to `isEnabled()` (T003) |
| Cross-chapter unlock logic | Added `getPreviousLesson()` with cross-chapter support (T015) |
| Backfill existing content | Added T093 for existing ratings/comments |
| Referred-by tracking | Added T091, T092 for persistent referral tracking |
