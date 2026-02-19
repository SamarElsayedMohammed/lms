# Phase 2: Content Access Control — Implementation Plan

**Spec**: `phase2-spec.md` | **Est. Hours**: 18-22h

---

## Technical Context

| Item | Value |
|------|-------|
| **Stack** | Laravel 12, PHP 8.3 |
| **Storage** | MySQL (existing) |
| **Auth** | Sanctum |
| **Existing** | `user_curriculum_tracking`, `courses`, `course_chapter_lectures` |

---

## Data Model

### 2.1 Video Progress Tracking

**Option A**: Extend `user_curriculum_tracking` (if schema allows)  
**Option B**: New table `video_progress` (recommended for clarity)

#### [NEW] Migration: `create_video_progress_table`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint PK | |
| user_id | foreignId | users.id |
| lecture_id | foreignId | course_chapter_lectures.id |
| watched_seconds | integer | Actual seconds watched (server-validated) |
| total_seconds | integer | Total video duration |
| last_position | integer | Last playback position (for resume) |
| watch_percentage | decimal(5,2) | watched_seconds/total_seconds * 100 |
| is_completed | boolean | True when watch_percentage >= 85 |
| completed_at | timestamp nullable | When 85% reached |
| created_at, updated_at | timestamps | |

**Unique**: (user_id, lecture_id)

### 2.2 Free Courses & Lessons

#### [MODIFY] Migration: `add_is_free_to_courses_and_lectures`

| Table | Field | Type | Default |
|-------|-------|------|---------|
| courses | is_free | boolean | false |
| courses | is_free_until | timestamp nullable | null |
| course_chapter_lectures | is_free | boolean | false |

### 2.3 Lecture Attachments

#### [NEW] Migration: `create_lecture_attachments_table`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint PK | |
| lecture_id | foreignId | course_chapter_lectures.id |
| file_name | string | Original filename |
| file_path | string | Storage path |
| file_size | integer | Bytes |
| file_type | string | MIME type |
| sort_order | integer | Display order |
| created_at, updated_at | timestamps | |

### 2.4 Feature Flags

#### [NEW] Migration: `create_feature_flags_table`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint PK | |
| key | string unique | Feature key |
| name | string | Display name |
| description | text nullable | |
| is_enabled | boolean | Default false |
| metadata | json nullable | |
| created_at, updated_at | timestamps | |

**Initial seeds**: lecture_attachments (false), affiliate_system (false), video_progress_enforcement (true), comments_require_approval (true), ratings_require_approval (true)

---

## Services

### VideoProgressService

```php
updateProgress(User, Lecture, watchedSeconds, lastPosition, totalSeconds): VideoProgress
getProgress(User, Lecture): ?array
canAccessNextLesson(User, Lecture): bool
getCourseProgress(User, Course): float
validateProgressChallenge(User, Lecture, challengeResponse): bool
generateProgressChallenge(User, Lecture): array
```

### FeatureFlagService

```php
isEnabled(string $key): bool
get(string $key): ?FeatureFlag
getAll(): Collection
```

### ContentAccessService (or extend CheckActiveSubscription)

```php
canAccessLecture(User, Lecture): bool  // Unified access logic
```

---

## API Contracts

### Video Progress

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/lecture/{id}/progress` | Yes | Update watch progress (throttled) |
| GET | `/api/lecture/{id}/progress` | Yes | Get progress + last_position |
| GET | `/api/course/{id}/progress` | Yes | Full course progress breakdown |

### Lecture Attachments

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/lecture/{id}/attachments` | Yes | List (if feature enabled) |
| POST | `/api/admin/lecture/{id}/attachments` | Admin | Upload |
| DELETE | `/api/admin/lecture/{id}/attachments/{attachmentId}` | Admin | Delete |

### Feature Flags (internal / admin)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/marketing-pixels/active` | No | Existing; add `/api/feature-flags/active` if needed for frontend |

---

## Middleware / Access Flow

1. **CheckActiveSubscription** (existing): Verify auth + subscription for paid content
2. **Modify** to inject free-course/lecture check before subscription check
3. **New**: Sequential lesson unlock — before serving lecture, verify previous lesson >= 85% (when feature enabled)

---

## Execution Order

1. Feature flags (foundation for other toggles)
2. Free courses/lessons (migrations + access logic)
3. Video progress (migrations, service, APIs)
4. Lecture attachments (migrations, CRUD, API behind feature flag)
