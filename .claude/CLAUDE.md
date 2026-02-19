# LMS v1.0.2 - Project Instructions

## Project Overview
Laravel-based Learning Management System with REST API (150+ endpoints).

## Tech Stack
- **Framework:** Laravel 10+ with PHP 8.1+
- **Database:** MySQL 8.0
- **Auth:** Laravel Sanctum + Firebase
- **API:** RESTful with JSON responses

## Development Commands
```bash
# Start server
php artisan serve

# Clear all cache
php artisan config:clear && php artisan cache:clear && php artisan route:clear

# Run tests
php artisan test
```

## Code Style
- Use type hints for all function parameters and returns
- Follow PSR-12 coding standards
- Use Laravel conventions for naming (PascalCase for classes, camelCase for methods)
- Always validate API requests using Form Requests or inline validation
- Handle exceptions with try-catch and return proper JSON responses

## API Response Format
```php
// Success
ApiResponseService::successResponse('Message', $data);

// Error
ApiResponseService::errorResponse('Error message', $exception);

// Validation Error
ApiResponseService::validationError('Validation message');
```

## File Locations
| Type | Path |
|------|------|
| API Controllers | `app/Http/Controllers/API/` |
| Services | `app/Services/` |
| Models | `app/Models/` |
| API Routes | `routes/api.php` |
| Firebase Credentials | `storage/app/firebase/` |

## Important Notes
- Always use `auth:sanctum` middleware for protected routes
- Firebase token verification uses `ApiService::verifyFirebaseToken()`
- Check `.agent/memory-bank/` for project context and decisions
- Postman collection: `LMS_API_Collection.postman_collection.json`
