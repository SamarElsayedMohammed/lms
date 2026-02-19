---
name: test-driven-development
description: TDD workflow for Laravel development
---

# Test-Driven Development Skill

## TDD Cycle

```
   ┌─────────┐
   │  RED    │ ← Write failing test
   └────┬────┘
        │
   ┌────▼────┐
   │  GREEN  │ ← Write minimal code to pass
   └────┬────┘
        │
   ┌────▼────┐
   │REFACTOR │ ← Improve code quality
   └────┬────┘
        │
        └───────→ Repeat
```

## Step 1: Write the Test First (RED)

```php
public function test_user_can_create_course(): void
{
    $instructor = User::factory()->create(['role' => 'instructor']);
    
    $response = $this->actingAs($instructor, 'sanctum')
        ->postJson('/api/instructor/courses', [
            'title' => 'Test Course',
            'description' => 'Test Description',
        ]);
    
    $response->assertStatus(201);
    $this->assertDatabaseHas('courses', ['title' => 'Test Course']);
}
```

## Step 2: Run Test (Should Fail)

```bash
php artisan test --filter=test_user_can_create_course
```

## Step 3: Write Code (GREEN)

Write the minimum code to make the test pass.

## Step 4: Refactor

- Clean up the code
- Remove duplication
- Improve naming
- Keep tests passing!

## Commands

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage

# Run specific test class
php artisan test tests/Feature/CourseTest.php

# Run in parallel
php artisan test --parallel
```

## Test Naming Convention

```
test_[what]_[action]_[expected_result]

Examples:
- test_guest_cannot_access_dashboard
- test_user_can_update_profile
- test_validation_fails_without_email
```
