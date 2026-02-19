# Phase 2 API Contracts

---

## POST `/api/lecture/{id}/progress`

**Auth**: Required (Sanctum)

**Request (JSON)**:
```json
{
  "watched_seconds": 120,
  "last_position": 125,
  "total_seconds": 300
}
```

**Response 200**:
```json
{
  "status": true,
  "message": "Progress updated",
  "data": {
    "watched_seconds": 120,
    "watch_percentage": 40.00,
    "is_completed": false,
    "last_position": 125
  }
}
```

**Throttle**: 10 requests/minute per user per lecture

---

## GET `/api/lecture/{id}/progress`

**Auth**: Required

**Response 200**:
```json
{
  "status": true,
  "data": {
    "watched_seconds": 120,
    "total_seconds": 300,
    "watch_percentage": 40.00,
    "last_position": 125,
    "is_completed": false
  }
}
```

---

## GET `/api/course/{id}/progress`

**Auth**: Required

**Response 200**:
```json
{
  "status": true,
  "data": {
    "course_id": 1,
    "overall_percentage": 65.5,
    "lessons": [
      {
        "lecture_id": 1,
        "title": "...",
        "watch_percentage": 100,
        "is_completed": true
      },
      {
        "lecture_id": 2,
        "title": "...",
        "watch_percentage": 45,
        "is_completed": false
      }
    ]
  }
}
```

---

## GET `/api/lecture/{id}/attachments`

**Auth**: Required

**Condition**: Returns data only if `feature_lecture_attachments_enabled` is true. Otherwise empty array or 404.

**Response 200**:
```json
{
  "status": true,
  "data": {
    "attachments": [
      {
        "id": 1,
        "file_name": "notes.pdf",
        "file_url": "/storage/...",
        "file_size": 102400,
        "file_type": "application/pdf"
      }
    ]
  }
}
```

---

## POST `/api/admin/lecture/{id}/attachments`

**Auth**: Admin

**Request**: multipart/form-data, file upload

**Response 201**:
```json
{
  "status": true,
  "message": "Attachment uploaded",
  "data": {
    "id": 1,
    "file_name": "notes.pdf",
    "file_url": "/storage/..."
  }
}
```

---

## DELETE `/api/admin/lecture/{id}/attachments/{attachmentId}`

**Auth**: Admin

**Response 200**:
```json
{
  "status": true,
  "message": "Attachment deleted"
}
```
