# API Contract: Content Approval

## Admin Approval Endpoints (T070, T071)

### GET /admin/approvals
Admin page showing pending ratings and comments. Requires: `approve_comments` or `approve_ratings`.

### GET /api/admin/reviews/pending
List pending ratings. Requires: `approve_ratings`.
```json
{
  "success": true,
  "data": [{
    "id": 1,
    "user": { "id": 5, "name": "Ahmed" },
    "course": { "id": 3, "title": "Laravel" },
    "rating": 5,
    "comment": "Great course",
    "status": "pending",
    "created_at": "2026-02-18"
  }]
}
```

### POST /api/admin/reviews/{id}/approve
Approve a rating. Requires: `approve_ratings`.
```json
{ "success": true, "message": "Rating approved" }
```

### POST /api/admin/reviews/{id}/reject
Reject a rating. Requires: `approve_ratings`.
```json
{ "success": true, "message": "Rating rejected" }
```

### GET /api/admin/comments/pending
List pending comments. Requires: `approve_comments`.

### POST /api/admin/comments/{id}/approve
Approve a comment. Requires: `approve_comments`.

### POST /api/admin/comments/{id}/reject
Reject a comment. Requires: `approve_comments`.

## Public API Changes (T069)

### GET /api/courses/{id}/ratings (existing, modified)
When feature flag `content_approval` is enabled, only return ratings with `status=approved`.

### POST /api/courses/{id}/ratings (existing, modified)
New ratings default to `status=pending` when feature flag is enabled.

### GET /api/courses/{id}/discussions (existing, modified)
When feature flag `content_approval` is enabled, only return comments with `status=approved`.
