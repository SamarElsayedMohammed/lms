# API Development Rules

## Route Naming
```php
// Public routes
Route::get('resource', [Controller::class, 'index']);
Route::get('resource/{id}', [Controller::class, 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('resource', [Controller::class, 'store']);
    Route::put('resource/{id}', [Controller::class, 'update']);
    Route::delete('resource/{id}', [Controller::class, 'destroy']);
});
```

## Response Standards
Always return consistent JSON structure:
```php
// Success
{
    "error": false,
    "message": "Success message",
    "data": { ... }
}

// Error
{
    "error": true,
    "message": "Error message",
    "data": null
}
```

## HTTP Status Codes
| Code | Usage |
|------|-------|
| 200 | Success |
| 201 | Created |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 422 | Validation Error |
| 500 | Server Error |

## Request Validation
- Validate all input data
- Return 422 for validation errors
- Use clear validation messages

## Pagination
```php
$data = Model::paginate(15);
return ApiResponseService::successResponse('Data retrieved', $data);
```

## Error Handling
```php
try {
    // API logic
    ApiResponseService::successResponse('Success', $data);
} catch (Throwable $th) {
    DB::rollBack();
    ApiResponseService::errorResponse(exception: $th);
}
```

## Testing APIs
```bash
# Test with curl
curl -X GET 'http://127.0.0.1:8000/api/endpoint' \
  --header 'Authorization: Bearer TOKEN'

# Check routes
php artisan route:list --path=api
```
