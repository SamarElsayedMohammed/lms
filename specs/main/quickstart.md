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

---

## Remaining Plan v2 Phases (Not Yet in tasks.md)

Execution order for **Plan v2 Phase 2, 3, 4, 5, 6** (see `.agent/memory-bank/implementation_plan_v2.md` and `specs/main/contracts/`).

### Step 8: Phase 2 — Content Access & Video Progress
```
1. Migration: video progress table (or extend user_curriculum_tracking)
2. Migration: is_free, is_free_until on courses; is_free on course_chapter_lectures
3. Migration: lecture_attachments table
4. Migration: feature_flags table (or use settings)
5. VideoProgressService / ContentAccessService: updateProgress, getProgress, canAccessNextLesson, getCourseProgress
6. API: POST/GET /api/lecture/{id}/progress, GET /api/course/{id}/progress
7. Middleware: enforce subscription + previous-lesson 85% before next lesson
8. API: GET /api/lecture/{id}/attachments (gated); Admin: POST/DELETE attachments
9. Seed or settings: feature flags (lecture_attachments, video_progress_enforcement, etc.)
```
**Contracts**: `specs/main/contracts/content-access-api.md`

### Step 9: Phase 3 — Affiliate System
```
1. Migrations: affiliate_links, affiliate_commissions, affiliate_withdrawals, affiliate_settings (or settings)
2. AffiliateService: generateLink, trackClick, processReferral, getCommissions, requestWithdrawal, releaseCommissions (bi-monthly)
3. API: GET /api/affiliate/status, my-link, stats, commissions, withdrawals; POST withdraw
4. API: GET /api/ref/{code} (track click)
5. Admin API: settings, withdrawals/pending, approve, reject, commissions, stats
6. Hook: on first subscription payment of referred user → create AffiliateCommission
7. Artisan: affiliate:release-commissions (daily) — set pending→available by available_date
8. Feature toggle: when disabled, affiliate routes 404 / hidden
```
**Contracts**: `specs/main/contracts/affiliate-api.md`

### Step 10: Phase 4 — Wallet & Kashier (if not complete)
```
1. KashierCheckoutService: createCheckoutSession, verifyPayment, webhook
2. POST /webhooks/kashier (exclude CSRF)
3. Subscribe flow: wallet first, then Kashier for remainder
4. Wallet top-up via Kashier
5. Admin settings: kashier_merchant_id, kashier_api_key, kashier_webhook_secret
```

### Step 11: Phase 5 — Admin, Roles, Marketing Pixels (if not complete)
```
1. Supervisor permissions (already in tasks T021–T024)
2. Marketing pixels: table, model, admin CRUD, GET /api/marketing-pixels/active
3. Subscription plan admin CRUD + country prices (already in tasks)
```

### Step 12: Phase 6 — Notifications & Certificates
```
1. Commands: subscriptions:send-expiry-notifications (7d, 3d, 24h — push + email)
2. Commands: subscriptions:handle-expired (expire / auto-renew)
3. Mailable + Blade templates for expiry emails
4. Certificate: 100% progress gate; QR code (verification URL); embed in PDF
5. GET /certificate/verify/{number} — public verification page
6. Schedule: daily run of expiry notifications, handle-expired, affiliate:release-commissions
```
**Contracts**: `specs/main/contracts/certificate-api.md`
