# Quickstart: Implementing Remaining 23 Tasks

**Estimated Time**: 25.25 hours | **Priority**: All High

## Prerequisites

- PHP 8.3+, Laravel 12, MySQL running
- Existing migrations applied
- `composer install` completed
- Kashier, Spatie, endroid/qr-code packages installed

## Execution Order

Execute in this order to respect dependencies:

### Step 1: Seeders & Migrations (T052, T054, T065, T073) — 1.5h
```bash
# 1. Create SupportedCurrencySeeder (T052)
# 2. Run pricing migrations + seeder (T054)
php artisan migrate --force
php artisan db:seed --class=SupportedCurrencySeeder
# 3. Run permissions seeder (T065)
php artisan db:seed --class=RolePermissionSeeder
# 4. Run approval migration (T073) — already migrated, verify
php artisan migrate:status
```

### Step 2: Subscription Plan Admin UI (T008-T013) — 6.75h
```
1. T011 — Add sidebar link (0.5h) → sidebar.blade.php
2. T008 — Index view with create form (2h) → subscription-plans/index.blade.php
3. T009 — Edit page (1.5h) → subscription-plans/edit.blade.php
4. T010 — Show/details page (1.5h) → subscription-plans/show.blade.php
5. T012 — Create SubscriptionPlanSeeder (1h)
6. T013 — Run seeder (0.25h)
```

### Step 3: Localized Pricing (T051, T053) — 3h
```
1. T051 — Modify getPlans() to return localized prices (1h)
2. T053 — Country pricing section on plan edit page (2h)
```

### Step 4: Plan API Extensions (T014, T081, T082) — 3h
```
1. T014 — Add renew() to SubscriptionApiController (1h)
2. T081 — Add toggle/sort/countryPrices to controller (1.5h)
3. T082 — Register admin routes (0.5h)
```

### Step 5: Supervisor UI (T062-T064) — 3.25h
```
1. T062 — Rename Instructor → Supervisor in views (0.75h)
2. T063 — Permission assignment UI (1.5h)
3. T064 — Permission middleware on admin controllers (1h)
```

### Step 6: Approval Workflow (T069-T072) — 4.5h
```
1. T069 — Filter public API by approval status (1h)
2. T070 — Create ApprovalController (1.5h)
3. T071 — Register approval routes (0.5h)
4. T072 — Create approval admin UI (1.5h)
```

### Step 7: Final Verification (T098) — 2h
```
1. Feature flags toggle test
2. Subscription → commission → withdrawal flow
3. Pricing → Kashier → activation flow
4. 85% progress → certificate with QR
5. Notification commands (expiry + expired handler)
```

## Key Files to Create/Modify

| File | Action | Task |
|------|--------|------|
| `resources/views/admin/subscription-plans/index.blade.php` | Create | T008 |
| `resources/views/admin/subscription-plans/edit.blade.php` | Modify | T009, T053 |
| `resources/views/admin/subscription-plans/show.blade.php` | Create | T010 |
| `resources/views/components/sidebar.blade.php` | Modify | T011, T072 |
| `database/seeders/SubscriptionPlanSeeder.php` | Modify | T012 |
| `database/seeders/SupportedCurrencySeeder.php` | Create | T052 |
| `app/Http/Controllers/API/SubscriptionApiController.php` | Modify | T014, T051 |
| `app/Http/Controllers/Admin/SubscriptionPlanController.php` | Modify | T081 |
| `app/Http/Controllers/Admin/ApprovalController.php` | Create | T070 |
| `resources/views/admin/approvals/index.blade.php` | Create | T072 |
| `routes/web.php` | Modify | T071, T082 |
| `routes/api.php` | Modify | T014, T082 |
