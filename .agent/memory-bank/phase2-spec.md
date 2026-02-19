# Phase 2: Content Access Control — Feature Specification

**Source**: `implementation_plan_v2.md` | **Date**: 2026-02-15

---

## Overview

Phase 2 implements content access control for the LMS subscription system:
- **85% video watch rule** before unlocking the next lesson
- **Free courses & lessons** (admin-configurable)
- **File attachments under videos** (admin toggle, initially hidden)
- **Feature flags system** for admin-controlled feature rollout

---

## Functional Requirements

### FR-2.1 Video Progress Enforcement (85% Rule)

| ID | Requirement | Acceptance Criteria |
|----|-------------|---------------------|
| FR-2.1.1 | Student must watch 85% of actual video duration before next lesson unlocks | `canAccessNextLesson()` returns true only when `watch_percentage >= 85` |
| FR-2.1.2 | Progress persists across sessions | `last_position` stored for resume; `watched_seconds` cumulative |
| FR-2.1.3 | Anti-cheating: hybrid client + server validation | Server can challenge client; random validation prevents bulk manipulation |
| FR-2.1.4 | First lesson always accessible | No previous-lesson check for first curriculum item |

### FR-2.2 Free Courses & Lessons

| ID | Requirement | Acceptance Criteria |
|----|-------------|---------------------|
| FR-2.2.1 | Course can be marked free | `courses.is_free` boolean; entire course accessible without subscription |
| FR-2.2.2 | Course can be temporarily free | `courses.is_free_until` timestamp; free until date, then requires subscription |
| FR-2.2.3 | Individual lesson can be free | `course_chapter_lectures.is_free` boolean |
| FR-2.2.4 | Login required for all content | Even free content requires authenticated user |

### FR-2.3 File Attachments Under Videos

| ID | Requirement | Acceptance Criteria |
|----|-------------|---------------------|
| FR-2.3.1 | Admin can attach files to lectures | CRUD for lecture attachments |
| FR-2.3.2 | Feature toggle: initially disabled | `feature_lecture_attachments_enabled` = false; attachments hidden from users |
| FR-2.3.3 | When enabled, users see attachments | GET `/api/lecture/{id}/attachments` returns list if feature enabled |

### FR-2.4 Feature Flags System

| ID | Requirement | Acceptance Criteria |
|----|-------------|---------------------|
| FR-2.4.1 | Admin can toggle features globally | `feature_flags` table; key, is_enabled |
| FR-2.4.2 | Initial flags seeded | lecture_attachments, affiliate_system, video_progress_enforcement, comments_require_approval, ratings_require_approval |
| FR-2.4.3 | API to check feature status | Backend services can query `FeatureFlagService::isEnabled('key')` |

---

## Non-Functional Requirements

| ID | Requirement |
|----|-------------|
| NFR-2.1 | Video progress updates: max 1 request per 10 seconds per user/lecture (throttle) |
| NFR-2.2 | Access checks must complete in < 50ms (cache where appropriate) |
| NFR-2.3 | Feature flag checks use cache (avoid DB hit per request) |

---

## Access Logic (Unified)

```
IF user is NOT logged in → REJECT (login required for everything)
IF course.is_free OR lecture.is_free OR (course.is_free_until AND now < free_until) → ALLOW
IF user has active subscription → CHECK sequential unlock:
  IF video_progress_enforcement enabled:
    IF previous lesson exists AND previous lesson watch_percentage < 85 → REJECT
  ALLOW
ELSE → REJECT (subscription required)
```

---

## Edge Cases

- **Lifetime subscription**: Same as active; no expiry check
- **Lecture with no video**: Treat as completed (e.g. document/quiz) — no 85% rule
- **Course with 0 lessons**: Allow access (nothing to unlock)
- **Feature flag missing**: Default to safe value (e.g. video_progress_enforcement = true)
