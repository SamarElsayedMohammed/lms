# Laravel Development Rules

## Controller Guidelines
- Place API controllers in `app/Http/Controllers/API/`
- Extend from `Controller` base class
- Use dependency injection for services
- Keep controllers thin, move business logic to services

## Model Guidelines
- Use Eloquent relationships properly
- Define `$fillable` or `$guarded` arrays
- Use soft deletes where appropriate
- Add type hints for relationships

## Service Layer
- Create services in `app/Services/`
- Handle complex business logic in services
- Return data, not responses (let controllers handle responses)

## Validation
```php
// Option 1: Inline validation
ApiService::validateRequest($request, [
    'field' => 'required|string|max:255',
]);

// Option 2: Form Request
public function store(StoreUserRequest $request) {}
```

## Database
- Use migrations for all schema changes
- Use factories and seeders for test data
- Always use transactions for multi-table operations:
```php
DB::beginTransaction();
try {
    // operations
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

## Authentication
- Use `auth:sanctum` middleware for API routes
- Check roles with `$user->hasRole('role_name')`
- System roles: `general_user`, `instructor`, `admin`, `team`

## File Uploads
- Store in `storage/app/public/`
- Use `FileService` for file operations
- Validate file types and sizes
