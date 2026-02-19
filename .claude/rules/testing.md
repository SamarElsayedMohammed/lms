# Testing Rules

## Test Structure
```
tests/
├── Feature/          # Integration tests
│   └── Api/          # API endpoint tests
└── Unit/             # Unit tests
    └── Services/     # Service layer tests
```

## Writing Tests
```php
class ExampleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_success(): void
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/endpoint');
        
        $response->assertStatus(200)
            ->assertJson(['error' => false]);
    }
}
```

## Test Commands
```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/Api/ExampleTest.php

# Run with coverage
php artisan test --coverage
```

## What to Test
- [ ] API returns correct status codes
- [ ] Validation rejects invalid data
- [ ] Authentication blocks unauthorized access
- [ ] Database changes are correct
- [ ] Response structure matches expected format

## Factories
Use factories for test data:
```php
User::factory()->create(['role' => 'instructor']);
Course::factory()->count(5)->create();
```
