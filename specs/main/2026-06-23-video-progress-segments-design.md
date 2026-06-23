# Video Progress Tracking with Watched Segments

**Date:** 2026-06-23  
**Status:** Approved  
**Author:** AI Assistant

## Overview

Implement accurate video progress tracking using a segment-based approach. This prevents users from fast-forwarding through videos while still allowing them to review previously watched content.

## Problem Statement

Currently, the video progress system relies on frontend-reported values (watched_seconds, last_position) which can be easily manipulated. Users can skip ahead and report fake progress, defeating the purpose of requiring full video completion before accessing the next lesson.

## Solution: Watched Segments Tracking

### Core Concept

Divide each video into small segments (5 seconds each). Track which segments the user has actually watched. A video is only marked as "completed" when all segments (100%) have been watched.

**Example:**
- 5-minute video (300 seconds) = 60 segments
- User watches segments 0-20, then skips to 40-45
- Progress: 26/60 = 43.3% (not the 75% they might try to report)

## Requirements

| Requirement | Value |
|------------|-------|
| Segment size | 5 seconds |
| Completion threshold | 100% |
| Update frequency | Every 15 seconds |
| Sequential unlock | Required (must complete previous lesson) |
| After completion | Free navigation allowed |
| Rewatch behavior | Continue from last position, can review watched parts |

## Data Model

### Modified `video_progress` Table

```sql
-- New columns to add
ALTER TABLE video_progress ADD COLUMN watched_segments JSON DEFAULT '[]';
ALTER TABLE video_progress ADD COLUMN segment_size INT DEFAULT 5;
ALTER TABLE video_progress ADD COLUMN total_segments INT DEFAULT 0;
ALTER TABLE video_progress ADD COLUMN completed_segments INT DEFAULT 0;
```

### Schema

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | User ID (FK) |
| lecture_id | bigint | Lecture ID (FK) |
| watched_seconds | int | Total watched time (legacy, keep for compatibility) |
| total_seconds | int | Video duration |
| last_position | int | Last playback position |
| watch_percentage | float | Calculated: completed_segments / total_segments * 100 |
| is_completed | boolean | True when watch_percentage >= 100 |
| completed_at | timestamp | When completion occurred |
| **watched_segments** | JSON | Array of 0/1 for each segment |
| **segment_size** | int | Segment duration (default 5s) |
| **total_segments** | int | Total number of segments |
| **completed_segments** | int | Count of watched segments |

### Data Example

```json
{
  "user_id": 123,
  "lecture_id": 456,
  "segment_size": 5,
  "total_segments": 60,
  "completed_segments": 45,
  "watched_segments": [1,1,1,1,1,0,0,0,1,1,1,1,...],
  "watch_percentage": 75.0,
  "last_position": 225,
  "is_completed": false
}
```

## API Specification

### 1. Update Progress

**Endpoint:** `POST /api/lecture/{lectureId}/progress`

**Request:**
```json
{
  "current_position": 127,
  "total_duration": 300,
  "newly_watched_segments": [25, 26, 27]
}
```

| Field | Type | Description |
|-------|------|-------------|
| current_position | int | Current playback position in seconds |
| total_duration | int | Total video duration in seconds |
| newly_watched_segments | int[] | Indices of newly watched segments (0-indexed) |

**Response:**
```json
{
  "success": true,
  "data": {
    "watch_percentage": 75.0,
    "is_completed": false,
    "completed_segments": 45,
    "total_segments": 60,
    "last_position": 127,
    "can_seek_to": 225
  }
}
```

**Validation Rules:**
- `current_position`: required, integer, min:0
- `total_duration`: required, integer, min:1
- `newly_watched_segments`: required, array
- `newly_watched_segments.*`: integer, min:0
- Max 3 new segments per request (prevents bulk manipulation)

### 2. Get Progress

**Endpoint:** `GET /api/lecture/{lectureId}/progress`

**Response:**
```json
{
  "success": true,
  "data": {
    "watch_percentage": 75.0,
    "is_completed": false,
    "last_position": 127,
    "can_seek_to": 225,
    "watched_segments": [1,1,1,1,1,0,0,0,1,1,1,...],
    "total_segments": 60,
    "resume_from": 127
  }
}
```

| Field | Description |
|-------|-------------|
| watch_percentage | Current progress percentage |
| is_completed | Whether video is fully watched |
| last_position | Last playback position |
| can_seek_to | Maximum seekable position (highest continuously watched point) |
| watched_segments | Bitmap of watched segments |
| resume_from | Position to start playback from |

### 3. Check Can Access

**Endpoint:** `GET /api/lecture/{lectureId}/can-access`

**Response:**
```json
{
  "success": true,
  "data": {
    "can_access": false,
    "reason": "previous_incomplete",
    "previous_lecture_id": 455,
    "previous_progress": 85.0
  }
}
```

## Backend Architecture

### Modified Files

```
app/
├── Models/
│   └── VideoProgress.php            # Add new fields, casts
├── Services/
│   └── VideoProgressService.php     # Add segment logic
├── Http/
│   └── Controllers/API/
│       └── LectureProgressApiController.php  # Update methods
database/
└── migrations/
    └── 2026_06_23_000000_add_segments_to_video_progress.php
```

### VideoProgressService Changes

```php
final class VideoProgressService
{
    public const COMPLETION_THRESHOLD = 100.0;
    public const DEFAULT_SEGMENT_SIZE = 5;
    
    /**
     * Update progress using segment-based tracking
     */
    public function updateSegmentProgress(
        User $user,
        CourseChapterLecture $lecture,
        int $currentPosition,
        int $totalDuration,
        array $newlyWatchedSegments
    ): VideoProgress;
    
    /**
     * Get maximum position user can seek to
     * (highest continuously watched segment from start)
     */
    public function getMaxSeekablePosition(VideoProgress $progress): int;
    
    /**
     * Initialize segments array for a new video
     */
    private function initializeSegments(int $totalDuration): array;
}
```

### VideoProgress Model Changes

```php
protected $casts = [
    'is_completed' => 'boolean',
    'completed_at' => 'datetime',
    'watch_percentage' => 'float',
    'watched_segments' => 'array',  // NEW
];

protected $fillable = [
    // ... existing
    'watched_segments',
    'segment_size',
    'total_segments',
    'completed_segments',
];
```

## Frontend Integration

### Bunny Stream Integration

Videos are hosted on Bunny Stream and embedded via iframe:
```
https://iframe.mediadelivery.net/embed/{library_id}/{video_id}
```

**Communication via postMessage:**
```javascript
// Listen for Bunny player events
window.addEventListener('message', (event) => {
    if (event.origin !== 'https://iframe.mediadelivery.net') return;
    
    const data = event.data;
    if (data.event === 'timeupdate') {
        handleTimeUpdate(data.currentTime);
    }
});
```

### Frontend Tracking Logic

```javascript
class VideoProgressTracker {
    constructor(lectureId) {
        this.lectureId = lectureId;
        this.segmentSize = 5;
        this.watchedInSession = new Set();
        this.serverProgress = null;
        this.lastSentTime = 0;
    }
    
    async init() {
        // Load existing progress
        this.serverProgress = await this.fetchProgress();
        return this.serverProgress.resume_from;
    }
    
    handleTimeUpdate(currentTime) {
        const segmentIndex = Math.floor(currentTime / this.segmentSize);
        
        // Only track if not already watched
        if (!this.isSegmentWatched(segmentIndex)) {
            this.watchedInSession.add(segmentIndex);
        }
        
        // Send every 15 seconds
        if (Date.now() - this.lastSentTime >= 15000) {
            this.sendProgress(currentTime);
        }
    }
    
    isSegmentWatched(index) {
        return this.serverProgress?.watched_segments?.[index] === 1 
            || this.watchedInSession.has(index);
    }
    
    async sendProgress(currentTime) {
        if (this.watchedInSession.size === 0) return;
        
        const response = await fetch(`/api/lecture/${this.lectureId}/progress`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_position: Math.floor(currentTime),
                total_duration: this.totalDuration,
                newly_watched_segments: Array.from(this.watchedInSession)
            })
        });
        
        this.serverProgress = await response.json();
        this.watchedInSession.clear();
        this.lastSentTime = Date.now();
    }
}
```

### Offline Handling

```javascript
// Save to localStorage when offline
window.addEventListener('offline', () => {
    localStorage.setItem(`pending_progress_${lectureId}`, 
        JSON.stringify([...watchedInSession]));
});

// Restore and send when online
window.addEventListener('online', async () => {
    const pending = JSON.parse(
        localStorage.getItem(`pending_progress_${lectureId}`) || '[]'
    );
    if (pending.length > 0) {
        await sendProgress(pending);
        localStorage.removeItem(`pending_progress_${lectureId}`);
    }
});

// Send before page close
window.addEventListener('beforeunload', () => {
    navigator.sendBeacon(
        `/api/lecture/${lectureId}/progress`,
        JSON.stringify({
            current_position: currentPosition,
            total_duration: totalDuration,
            newly_watched_segments: [...watchedInSession]
        })
    );
});
```

## Performance Considerations

### Update Frequency

| Concurrent Users | Requests/Second (15s interval) |
|-----------------|-------------------------------|
| 100 | 6.7 req/sec |
| 500 | 33 req/sec |
| 1000 | 67 req/sec |
| 5000 | 333 req/sec |

### Optimizations

1. **Batch updates** - Send multiple segments per request
2. **Smart sending** - Only send when paused or every 15s
3. **Local caching** - Cache progress in browser
4. **Database indexes** - Index on (user_id, lecture_id)

## Security Considerations

1. **Rate limiting** - Max 3 segments per request
2. **Validation** - Segment indices must be valid (0 to total_segments-1)
3. **Time validation** - Can't report more segments than time elapsed
4. **Authentication** - All endpoints require authenticated user

## Migration Plan

### Phase 1: Database Migration
- Add new columns to video_progress table
- Backfill existing records with calculated segments

### Phase 2: Backend Updates
- Update VideoProgressService
- Update API controller
- Maintain backward compatibility

### Phase 3: Frontend Integration
- Implement postMessage listener for Bunny
- Add segment tracking logic
- Update progress UI

### Backward Compatibility

The API will accept both old format (watched_seconds only) and new format (segments array). Old records will be migrated to calculate watched_segments based on watched_seconds.

## Testing Strategy

1. **Unit Tests**
   - Segment calculation logic
   - Progress percentage calculation
   - Seek position validation

2. **Integration Tests**
   - API endpoints
   - Database operations
   - Progress synchronization

3. **E2E Tests**
   - Full video watch flow
   - Offline/online transitions
   - Browser close handling

## Success Criteria

- [ ] Users cannot skip ahead and get progress credit
- [ ] Progress updates survive page refresh
- [ ] Progress updates survive offline periods
- [ ] Sequential lesson unlock works correctly
- [ ] Users can freely navigate after completion
- [ ] Performance stays under 100 req/sec at 1000 concurrent users
