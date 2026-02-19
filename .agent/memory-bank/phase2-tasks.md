# Phase 2 Tasks: Content Access Control

**Input**: `phase2-spec.md`, `phase2-plan.md`, `phase2-contracts.md`  
**Prerequisites**: Phase 1 complete (subscription system)  
**Total Tasks**: 28 | **Est. Hours**: 18-22h

**Format**: `- [ ] [ID] [P?] [Story] Description` | **[P]** = parallelizable | **[USn]** = user story

---

## Phase 1: Foundational — Feature Flags (US0)

**Goal**: Admin-controllable feature toggles; all other Phase 2 features depend on this.

**Independent Test**: `FeatureFlagService::isEnabled('video_progress_enforcement')` returns true; cache hit on second call.

---

### T001 Create migration `create_feature_flags_table`

- [ ] T001 Create migration in `database/migrations/YYYY_MM_DD_HHMMSS_create_feature_flags_table.php`

**Details**:
- Table: `feature_flags`
- Columns: `id`, `key` (string, unique), `name` (string), `description` (text nullable), `is_enabled` (boolean, default false), `metadata` (json nullable), `timestamps`
- Index on `key` for fast lookups

**Acceptance**: Migration runs without error; table exists.

---

### T002 [P] Create `FeatureFlag` model

- [ ] T002 [P] Create `FeatureFlag` model in `app/Models/FeatureFlag.php`

**Details**:
- Fillable: `key`, `name`, `description`, `is_enabled`, `metadata`
- Casts: `is_enabled` => boolean, `metadata` => array
- Add `scopeEnabled()` for `where('is_enabled', true)`

**Acceptance**: `FeatureFlag::where('key', 'x')->first()` works.

---

### T003 Create `FeatureFlagService`

- [ ] T003 Create `FeatureFlagService` in `app/Services/FeatureFlagService.php`

**Details**:
- `isEnabled(string $key): bool` — Check cache first (key: `feature_flag:{key}`), return DB value if miss; cache TTL 3600s
- `get(string $key): ?FeatureFlag` — Same cache logic, return model or null
- `getAll(): Collection` — Return all flags (cached as `feature_flags:all`)
- Use `CachingService` or `Cache::remember()` pattern from existing codebase

**Acceptance**: `app(FeatureFlagService::class)->isEnabled('video_progress_enforcement')` returns true after seed; second call hits cache.

---

### T004 Create `FeatureFlagSeeder`

- [ ] T004 Create `FeatureFlagSeeder` in `database/seeders/FeatureFlagSeeder.php`

**Details**:
- Seed 5 flags via `updateOrCreate(['key' => $key], $data)`:
  - `lecture_attachments` — name: "ملفات مرفقة تحت الفيديو", is_enabled: false
  - `affiliate_system` — name: "نظام التسويق بالعمولة", is_enabled: false
  - `video_progress_enforcement` — name: "إلزام مشاهدة 85%", is_enabled: true
  - `comments_require_approval` — name: "موافقة الأدمن على التعليقات", is_enabled: true
  - `ratings_require_approval` — name: "موافقة الأدمن على التقييمات", is_enabled: true

**Acceptance**: `php artisan db:seed --class=FeatureFlagSeeder`; all 5 rows exist.

---

### T005 Run migration and seeder

- [ ] T005 Run `php artisan migrate` and `php artisan db:seed --class=FeatureFlagSeeder`

**Details**:
- `php artisan migrate` (for feature_flags)
- `php artisan db:seed --class=FeatureFlagSeeder`
- Add `FeatureFlagSeeder` to `DatabaseSeeder` if desired

**Acceptance**: Table populated; no errors.

---

## Phase 2: Free Courses & Lessons (US1)

**Goal**: Courses/lessons can be marked free; access bypasses subscription.

**Independent Test**: Create course with `is_free=true`; unauthenticated user gets 401; authenticated user without subscription gets 200.

---

### T006 [P] [US1] Create migration `add_is_free_to_courses_and_lectures`

- [ ] T006 [P] [US1] Create migration in `database/migrations/YYYY_MM_DD_HHMMSS_add_is_free_to_courses_and_lectures.php`

**Details**:
- `courses`: add `is_free` (boolean, default false), `is_free_until` (timestamp nullable)
- `course_chapter_lectures`: add `is_free` (boolean, default false)

**Acceptance**: Migration runs; columns exist.

---

### T007 [US1] Update `Course` model

- [ ] T007 [US1] Update `Course` model in `app/Models/Course/Course.php`

**Details**:
- Add `is_free`, `is_free_until` to fillable
- Casts: `is_free` => boolean, `is_free_until` => datetime
- Add helper: `isFreeForUser(User $user): bool` — true if is_free OR (is_free_until && now < is_free_until)

**Acceptance**: `$course->is_free` accessible; `isFreeForUser()` works.

---

### T008 [US1] Update `CourseChapterLecture` model

- [ ] T008 [US1] Update `CourseChapterLecture` in `app/Models/Course/CourseChapter/Lecture/CourseChapterLecture.php`

**Details**:
- Add `is_free` to fillable and casts (boolean)

**Acceptance**: `$lecture->is_free` accessible.

---

### T009 [US1] Modify access logic

- [ ] T009 [US1] Create `ContentAccessService` in `app/Services/ContentAccessService.php` and integrate free-course check into lecture access flow

**Details**:
- New method: `canAccessLecture(User $user, CourseChapterLecture $lecture): bool`
- Logic: (1) If lecture.is_free → true; (2) Load course, if course.isFreeForUser($user) → true; (3) If subscriptionService.checkAccess($user) → true; (4) else false
- Integrate into routes that serve lecture content (e.g. video stream, lecture details). Identify where `CheckActiveSubscription` is applied; add free-course check before subscription check.

**Acceptance**: Free lecture returns 200 for user without subscription; paid lecture returns 403.

---

### T010 [US1] Add admin UI for free flags

- [ ] T010 [US1] Add is_free, is_free_until to course edit; is_free to lecture edit in `resources/views/` and controllers

**Details**:
- Course edit form: `resources/views/` (locate course edit view) — add checkbox `is_free`, date input `is_free_until`
- Lecture edit: locate lecture curriculum edit (e.g. in `CourseChaptersController` or curriculum form) — add checkbox `is_free`
- Save in controller store/update methods

**Acceptance**: Admin can set is_free; value persists and affects access.

---

## Phase 3: Video Progress — 85% Rule (US2)

**Goal**: Track watch progress; enforce 85% before next lesson unlocks.

**Independent Test**: POST progress to 85%; GET next lesson returns 200; POST progress to 50%; GET next lesson returns 403 if feature enabled.

---

### T011 [P] [US2] Create migration `create_video_progress_table`

- [ ] T011 [P] [US2] Create migration in `database/migrations/YYYY_MM_DD_HHMMSS_create_video_progress_table.php`

**Details**:
- Table: `video_progress`
- Columns: `user_id` (foreignId), `lecture_id` (foreignId), `watched_seconds` (integer), `total_seconds` (integer), `last_position` (integer), `watch_percentage` (decimal 5,2), `is_completed` (boolean), `completed_at` (timestamp nullable), `timestamps`
- Unique: `(user_id, lecture_id)`
- Indexes: user_id, lecture_id

**Acceptance**: Migration runs; table exists.

---

### T012 [US2] Create `VideoProgress` model

- [ ] T012 [US2] Create `VideoProgress` model in `app/Models/VideoProgress.php`

**Details**:
- Fillable: user_id, lecture_id, watched_seconds, total_seconds, last_position, watch_percentage, is_completed, completed_at
- Relations: `user()`, `lecture()` (belongsTo)
- Casts: is_completed => boolean, completed_at => datetime, watch_percentage => decimal
- Scope: `forUser($userId)`, `forLecture($lectureId)`

**Acceptance**: `VideoProgress::forUser(1)->forLecture(1)->first()` works.

---

### T013 [US2] Create `VideoProgressService`

- [ ] T013 [US2] Create `VideoProgressService` in `app/Services/VideoProgressService.php`

**Details**:
- `updateProgress(User, CourseChapterLecture, watchedSeconds, lastPosition, totalSeconds): VideoProgress` — updateOrCreate; if watch_percentage >= 85 set is_completed, completed_at
- `getProgress(User, CourseChapterLecture): ?array` — return watched_seconds, last_position, watch_percentage, is_completed
- `canAccessNextLesson(User, CourseChapterLecture): bool` — check FeatureFlagService::isEnabled('video_progress_enforcement'); if disabled return true; else get previous lesson in curriculum, if none return true; else check previous lesson watch_percentage >= 85
- `getCourseProgress(User, Course): float` — aggregate progress across all lectures in course; return 0–100

**Acceptance**: updateProgress; getProgress; canAccessNextLesson for first lesson returns true.

---

### T014 [US2] Add anti-cheat stubs

- [ ] T014 [US2] Add `validateProgressChallenge`, `generateProgressChallenge` stubs in `app/Services/VideoProgressService.php`

**Details**:
- `generateProgressChallenge(User, Lecture): array` — return e.g. `['token' => '...', 'timestamp' => ...]` (placeholder)
- `validateProgressChallenge(User, Lecture, array $response): bool` — return true (stub for now)

**Acceptance**: Methods exist; no errors when called.

---

### T015 [US2] Create `LectureProgressApiController`

- [ ] T015 [US2] Create `LectureProgressApiController` in `app/Http/Controllers/API/LectureProgressApiController.php`

**Details**:
- `updateProgress(Request, int $lectureId)` — validate watched_seconds, last_position, total_seconds; call VideoProgressService::updateProgress; return ApiResponseService::successResponse with progress data
- `getProgress(int $lectureId)` — return progress for auth user
- `getCourseProgress(int $courseId)` — return CourseApiController or new method; aggregate progress per lecture

**Acceptance**: POST/GET return 200 with valid data.

---

### T016 [US2] Register progress routes

- [ ] T016 [US2] Register POST/GET lecture progress and GET course progress routes in `routes/api.php`

**Details**:
- Under `auth:sanctum`: `POST /api/lecture/{id}/progress` → LectureProgressApiController@updateProgress
- `GET /api/lecture/{id}/progress` → LectureProgressApiController@getProgress
- `GET /api/course/{id}/progress` → LectureProgressApiController@getCourseProgress (or CourseApiController)

**Acceptance**: Routes resolve; 401 when unauthenticated.

---

### T017 [US2] Add throttle to progress endpoint

- [ ] T017 [US2] Add throttle (6/min) to POST `/api/lecture/{id}/progress` in `routes/api.php`

**Details**:
- Apply `throttle:6,1` (6 per minute) or custom rate limiter for `user:lecture:{userId}:{lectureId}`

**Acceptance**: 7th request within 1 min returns 429.

---

### T018 [US2] Integrate sequential unlock

- [ ] T018 [US2] Integrate `canAccessNextLesson` check before serving lecture in `VideoStreamController` or `CourseChapterApiController`

**Details**:
- Locate where lecture/video is served (e.g. `VideoStreamController`, `CourseChapterApiController` getLecture). Add check: if FeatureFlagService::isEnabled('video_progress_enforcement') && !VideoProgressService::canAccessNextLesson($user, $lecture) → return 403 with message

**Acceptance**: With 85% rule enabled, next lesson blocked until 85% reached.

---

### T019 [US2] Add curriculum helper for previous lesson

- [ ] T019 [US2] Add helper to get previous lesson in curriculum in `VideoProgressService` or `CourseChapter` model

**Details**:
- In `VideoProgressService` or new helper: given a lecture, get its course_chapter, get curriculum order, find previous item (lecture) in same chapter. Return null if first. Use `CourseChapter` curriculum/curriculumItems relationship.

**Acceptance**: Previous lesson correctly identified for any lecture in curriculum.

---

## Phase 4: Lecture Attachments (US3)

**Goal**: Admin can attach files to lectures; users see them when feature enabled.

**Independent Test**: Admin uploads file; GET `/api/lecture/{id}/attachments` returns empty when feature disabled (or 404); enable feature; returns list.

---

### T020 [P] [US3] Create migration `create_lecture_attachments_table`

- [ ] T020 [P] [US3] Create migration in `database/migrations/YYYY_MM_DD_HHMMSS_create_lecture_attachments_table.php`

**Details**:
- Table: `lecture_attachments`
- Columns: `lecture_id` (foreignId), `file_name` (string), `file_path` (string), `file_size` (integer), `file_type` (string), `sort_order` (integer), `timestamps`

**Acceptance**: Migration runs.

---

### T021 [US3] Create `LectureAttachment` model

- [ ] T021 [US3] Create `LectureAttachment` model in `app/Models/LectureAttachment.php`

**Details**:
- Fillable: lecture_id, file_name, file_path, file_size, file_type, sort_order
- Relation: `lecture()` belongsTo CourseChapterLecture
- Accessor: `file_url` — return full URL via FileService::getFileUrl or asset()/Storage::url

**Acceptance**: Model exists; relation works.

---

### T022 [US3] Create admin `LectureAttachmentController`

- [ ] T022 [US3] Create `LectureAttachmentController` in `app/Http/Controllers/Admin/LectureAttachmentController.php`

**Details**:
- `store(Request, int $lectureId)` — validate file; upload via FileService; create LectureAttachment; return JSON
- `destroy(int $lectureId, int $attachmentId)` — delete file from storage; delete record

**Acceptance**: Admin can upload and delete attachment.

---

### T023 [US3] Create user API for attachments

- [ ] T023 [US3] Create GET `/api/lecture/{id}/attachments` in `CourseChapterApiController` or new controller; gate by `FeatureFlagService::isEnabled('lecture_attachments')`

**Details**:
- Add to `CourseChapterApiController` or new controller; check `FeatureFlagService::isEnabled('lecture_attachments')`; if false return empty array or 404; else return attachments with file_url

**Acceptance**: Feature off → empty; feature on → list.

---

### T024 [US3] Register admin attachment routes

- [ ] T024 [US3] Register POST/DELETE admin lecture attachment routes in `routes/web.php` or `api.php`

**Details**:
- `POST /admin/lecture/{id}/attachments` → store
- `DELETE /admin/lecture/{id}/attachments/{attachmentId}` → destroy
- Permission: e.g. `course-chapters-edit` or new `lecture-attachments-manage`

**Acceptance**: Routes resolve; 403 when unauthorized.

---

### T025 [US3] Add admin UI for attachments

- [ ] T025 [US3] Add attachments section to lecture curriculum edit view in `resources/views/`

**Details**:
- Locate lecture curriculum edit view (e.g. in course chapter curriculum edit). Add section "Attachments" with upload form and list; each row has delete button. Use existing FileService upload pattern.

**Acceptance**: Admin sees attachments; can add/remove.

---

## Phase 5: Admin Feature Flags UI (US4) — Optional

**Goal**: Admin can toggle feature flags from dashboard.

**Independent Test**: Admin toggles `lecture_attachments`; GET attachments API returns data.

---

### T026 [P] [US4] Create admin feature flags page

- [ ] T026 [P] [US4] Create feature flags index view in `resources/views/admin/feature-flags/index.blade.php`

**Details**:
- List all flags; each row: name, key, toggle switch for is_enabled
- Form submit via AJAX to update is_enabled; clear cache on update

**Acceptance**: Page loads; toggles visible.

---

### T027 [US4] Add feature flags permissions and routes

- [ ] T027 [US4] Add `feature-flags-list`, `feature-flags-edit` to `RolePermissionSeeder`; create `FeatureFlagController`; register routes in `routes/web.php`

**Details**:
- Controller: `FeatureFlagController` index, update (toggle)
- Route: `GET /feature-flags`, `POST /feature-flags/{id}/toggle`
- Permission seeder: add to RolePermissionSeeder; assign to admin

**Acceptance**: Admin can toggle; permission blocks non-admin.

---

### T028 [US4] Add sidebar link for feature flags

- [ ] T028 [US4] Add "Feature Flags" link in `resources/views/components/sidebar.blade.php`

**Details**:
- Under Settings or new section; `@can('feature-flags-list')`; link to feature-flags index

**Acceptance**: Link visible to admin.

---

## Dependencies & Execution Order

```
T001 → T002 → T003 → T004 → T005
T005 → T006 (parallel with T011, T020)
T006 → T007 → T008 → T009 → T010
T005 → T011 → T012 → T013 → T014 → T015 → T016 → T017 → T018 → T019
T005 → T020 → T021 → T022 → T023 → T024 → T025
T005 → T026 → T027 → T028
```

**Parallel after T005**: US1 (T006–T010) | US2 (T011–T019) | US3 (T020–T025) | US4 (T026–T028)

---

## Implementation Strategy

| Phase | Description | MVP? |
|-------|-------------|------|
| Phase 1 | Feature flags | ✅ Required |
| Phase 2 | Free courses/lessons | ✅ Required |
| Phase 3 | Video progress | ✅ Required |
| Phase 4 | Lecture attachments | ✅ Required |
| Phase 5 | Admin feature flags UI | Optional |

---

## Acceptance Criteria (Summary)

- [ ] Feature flags table exists; 5 flags seeded; `FeatureFlagService::isEnabled()` works with cache
- [ ] Free courses/lessons bypass subscription; access logic correct
- [ ] Video progress tracked; 85% rule enforced when feature enabled; APIs work; throttle applied
- [ ] Lecture attachments CRUD; user API returns list only when feature enabled
- [ ] Admin can toggle feature flags (if T026–T028 done)
