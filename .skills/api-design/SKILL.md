---
name: api-design
description: Best practices for REST API design
---

# API Design Skill

## RESTful Conventions

| Action | Method | Endpoint | Response |
|--------|--------|----------|----------|
| List | GET | `/api/resources` | 200 + array |
| Create | POST | `/api/resources` | 201 + object |
| Read | GET | `/api/resources/{id}` | 200 + object |
| Update | PUT | `/api/resources/{id}` | 200 + object |
| Delete | DELETE | `/api/resources/{id}` | 204 |

## Naming Guidelines

✅ **Do:**
- Use plural nouns: `/api/courses`
- Use kebab-case: `/api/course-chapters`
- Nest related resources: `/api/courses/{id}/chapters`

❌ **Don't:**
- Use verbs: `/api/getCourses`
- Use camelCase in URLs: `/api/courseChapters`
- Deep nesting (max 2 levels)

## Request Validation

```php
$validated = $request->validate([
    'title' => 'required|string|max:255',
    'description' => 'nullable|string',
    'price' => 'required|numeric|min:0',
    'category_id' => 'required|exists:categories,id',
]);
```

## Response Standards

```php
// Success with data
return response()->json([
    'error' => false,
    'message' => 'Course created successfully',
    'data' => $course
], 201);

// Error
return response()->json([
    'error' => true,
    'message' => 'Course not found',
    'data' => null
], 404);
```

## Pagination

```php
$courses = Course::with('instructor')
    ->where('status', 'published')
    ->paginate(15);

return ApiResponseService::successResponse('Courses retrieved', $courses);
```

## Filtering & Sorting

```php
$query = Course::query();

// Filtering
if ($request->has('category')) {
    $query->where('category_id', $request->category);
}

// Sorting
$query->orderBy(
    $request->get('sort_by', 'created_at'),
    $request->get('sort_order', 'desc')
);
```

## Rate Limiting

In `RouteServiceProvider`:
```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```
