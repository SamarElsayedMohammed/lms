# LMS Project Constitution

> **Core principles and standards governing all development on the LMS platform.**
> Last Updated: 2026-02-08

---

## 🏛️ Foundational Principles

### 1. Code Must Be Self-Documenting
Write code that explains itself. If you need a comment to explain *what* code does, rewrite the code.

### 2. Consistency Over Cleverness
Follow established patterns in the codebase even if you know a "better" way. Consistency aids maintenance.

### 3. Fail Fast, Recover Gracefully
Validate inputs early, throw meaningful exceptions, and always provide user-friendly error messages.

---

## 📐 Code Quality Standards

### PHP / Laravel Requirements

| Requirement | Standard |
|-------------|----------|
| **Type Hints** | Required on all method parameters and return types |
| **Strict Types** | `declare(strict_types=1);` in all files |
| **Method Length** | Max 25 lines (refactor if longer) |
| **Class Length** | Max 300 lines (split responsibilities if larger) |
| **Cyclomatic Complexity** | Max 10 per method |

### Naming Conventions

```php
// ✅ Correct
public function getUserSubscriptionStatus(): string {}
private bool $isSubscriptionActive;
const MAX_RETRY_ATTEMPTS = 3;

// ❌ Incorrect  
public function getStatus() {}  // Too vague
private $active;                // No type, unclear name
```

### Service Layer Pattern

```php
// Controllers MUST be thin - delegate to services
class SubscriptionController {
    public function subscribe(Request $request): JsonResponse {
        // Validate only
        $validated = $request->validate([...]);
        
        // Delegate to service
        $result = $this->subscriptionService->createSubscription($validated);
        
        // Return response only
        return response()->json($result);
    }
}
```

### Required Code Patterns

- [ ] **Repository Pattern** for complex queries (>3 conditions)
- [ ] **Service Classes** for business logic
- [ ] **Form Requests** for validation (not inline)
- [ ] **API Resources** for response transformation
- [ ] **Events/Listeners** for side effects (notifications, logging)

---

## 🧪 Testing Standards

### Minimum Coverage Requirements

| Type | Coverage | Focus |
|------|----------|-------|
| **Unit Tests** | 80%+ | Services, Helpers |
| **Feature Tests** | 100% | API endpoints |
| **Integration** | Critical paths | Payment, Subscriptions |

### Test Naming Convention

```php
/** @test */
public function user_with_active_subscription_can_access_premium_content(): void {}

/** @test */
public function expired_subscription_user_receives_403_forbidden(): void {}
```

### Test Structure (AAA Pattern)

```php
public function test_affiliate_commission_is_credited_on_referral_subscription(): void
{
    // Arrange
    $referrer = User::factory()->create();
    $affiliateLink = AffiliateLink::factory()->for($referrer)->create();
    
    // Act
    $referred = User::factory()->withReferral($affiliateLink->code)->create();
    $this->subscriptionService->createSubscription($referred);
    
    // Assert
    $this->assertDatabaseHas('wallet_histories', [
        'user_id' => $referrer->id,
        'type' => 'credit',
        'transaction_type' => 'affiliate_commission',
    ]);
}
```

### API Test Requirements

Every API endpoint MUST have tests for:
- [ ] **Happy path** (200/201 response)
- [ ] **Validation errors** (422 response)
- [ ] **Authentication required** (401 response)
- [ ] **Authorization denied** (403 response)
- [ ] **Resource not found** (404 response)

---

## 🎨 User Experience Consistency

### API Response Format

**All API responses MUST follow this structure:**

```json
// Success Response
{
  "status": true,
  "message": "تم الاشتراك بنجاح",
  "data": { ... }
}

// Error Response
{
  "status": false,
  "message": "حدث خطأ",
  "errors": { "field": ["error message"] }
}

// Paginated Response
{
  "status": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 15,
    "total": 150
  }
}
```

### Error Messages

| Context | Language | Example |
|---------|----------|---------|
| User-facing | Arabic (`ar`) | `الاشتراك منتهي الصلاحية` |
| Logs/Debug | English | `Subscription expired for user_id: 123` |
| Validation | Translatable | Using Laravel's `trans()` |

### Consistent Loading States

All long operations MUST provide:
- Immediate acknowledgment (HTTP 202 for async)
- Progress tracking for operations > 3 seconds
- Clear completion/failure notification

### Date/Time Standards

```php
// Always return ISO 8601 in APIs
'created_at' => $model->created_at->toIso8601String(),

// Display format for UI
'display_date' => $model->created_at->translatedFormat('j F Y'),
```

---

## ⚡ Performance Requirements

### API Response Time Limits

| Endpoint Type | Max Response Time |
|---------------|-------------------|
| List/Index | 500ms |
| Single Resource | 200ms |
| Create/Update | 1s |
| Complex Reports | 3s |
| File Upload | 10s |

### Database Query Standards

```php
// ✅ Required: Eager loading
$courses = Course::with(['chapters', 'instructor'])->get();

// ❌ Forbidden: N+1 queries
$courses = Course::all();
foreach ($courses as $course) {
    echo $course->instructor->name; // N+1!
}
```

### Query Limits

| Metric | Maximum |
|--------|---------|
| Queries per request | 20 |
| Rows fetched (list) | 100 (paginate larger) |
| JOIN operations | 4 tables |

### Caching Requirements

| Data Type | Cache Duration | Invalidation |
|-----------|----------------|--------------|
| Course lists | 1 hour | On course CRUD |
| User subscription status | 5 minutes | On payment |
| System settings | 24 hours | On admin update |
| Static content | 1 week | Manual |

### Index Requirements

Every table with > 10K rows MUST have indexes on:
- Foreign keys
- Columns used in WHERE clauses
- Columns used in ORDER BY

---

## 🔒 Security Non-Negotiables

1. **Never** - Store plain text passwords
2. **Never** - Expose internal IDs in error messages
3. **Never** - Trust client-side data for authorization
4. **Always** - Use parameterized queries
5. **Always** - Validate file uploads (type, size, content)
6. **Always** - Rate limit authentication endpoints

---

## 📋 Pre-Commit Checklist

Before pushing any code:

- [ ] PHPStan level 6+ passes
- [ ] All tests pass
- [ ] No `dd()`, `dump()`, or `var_dump()` left
- [ ] No hardcoded credentials or secrets
- [ ] API documentation updated (if applicable)
- [ ] Migration is reversible (has `down()` method)

---

## 🚫 Forbidden Practices

| Practice | Reason | Alternative |
|----------|--------|-------------|
| `@` error suppression | Hides bugs | Handle exceptions properly |
| `eval()` | Security risk | Use proper logic |
| `extract()` | Unclear variables | Use explicit assignment |
| Raw SQL in controllers | SQL injection risk | Eloquent or Query Builder |
| Hardcoded config values | Environment-dependent | Use `.env` / `config()` |

---

## ✅ Enforcement

These standards are enforced through:

1. **Automated** - PHPStan, Laravel Pint, GitHub Actions
2. **Code Review** - All PRs require approval
3. **Pre-deployment** - Test suite must pass

> *"The standard you walk past is the standard you accept."*
