# Tasks: LMS Remaining 23 Features

**Input**: Design documents from `/specs/main/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

---

## Phase 1: Foundation (Seeders & Migrations)

**Purpose**: Seed reference data and verify migrations — BLOCKS all subsequent phases

- [X] T001 [P] [FND] Create `database/seeders/SupportedCurrencySeeder.php` — seed EG (EGP, 1.0), SA (SAR, 0.19), AE (AED, 0.18), US (USD, 0.03). Use `firstOrCreate` for idempotency.
- [ ] T002 [FND] Run `php artisan migrate --force` — verify all pricing and approval migrations applied cleanly *(requires DB access)*
- [ ] T003 [FND] Run `php artisan db:seed --class=SupportedCurrencySeeder` — populate supported_currencies table *(requires DB access)*
- [ ] T004 [FND] Run `php artisan db:seed --class=RolePermissionSeeder` — ensure supervisor permissions + feature-flags permissions are seeded *(requires DB access)*
- [ ] T005 [FND] Verify migration status with `php artisan migrate:status` — confirm approval fields, pricing tables, affiliate tables all present *(requires DB access)*

**Checkpoint**: All reference data seeded, all migrations applied. User story work can begin.

---

## Phase 2: US1 — Subscription Plan Admin UI (Priority: P1)

**Goal**: Admin can create, view, edit, and manage subscription plans via the admin panel.

**Independent Test**: Navigate to `/admin/subscription-plans`, create a plan, edit it, view details, toggle active state.

### Implementation

- [X] T006 [US1] Add 'الاشتراكات' section to sidebar in `resources/views/components/sidebar.blade.php` — new header, menu item `{{ route('subscription-plans.index') }}`, icon `fas fa-gem`, wrapped in `@can('subscription-plans-list')`
- [X] T007 [US1] Create `resources/views/admin/subscription-plans/index.blade.php` — extends `layouts.app`, set `$type_menu = 'subscription-plans'`. Inline create form: name, billing_cycle (select with 6 options), custom_days (show/hide), price, commission_rate, features (dynamic add/remove). Data table listing all plans with columns: name, cycle, price, commission, active toggle, actions (edit/show/delete). AJAX create/delete.
- [X] T008 [US1] Create `resources/views/admin/subscription-plans/edit.blade.php` — extends `layouts.app`. Pre-filled form matching index create form. Dynamic features list with add/remove. Show/hide custom_days input when billing_cycle=custom. AJAX update via PUT.
- [X] T009 [US1] Create `resources/views/admin/subscription-plans/show.blade.php` — extends `layouts.app`. Plan details card (name, cycle, price, commission_rate, features list, created_at). Stats section: subscriber count, total revenue. Paginated subscribers table: user name, start date, end date, status.
- [X] T010 [US1] Update `database/seeders/SubscriptionPlanSeeder.php` — use `firstOrCreate`. 5 plans: Monthly (شهري, 100 EGP, 10%), Quarterly (ربع سنوي, 270, 12%), Semi-Annual (نصف سنوي, 500, 15%), Yearly (سنوي, 900, 20%), Lifetime (مدى الحياة, 2500, 25%). Features as JSON arrays in Arabic.
- [ ] T011 [US1] Run `php artisan db:seed --class=SubscriptionPlanSeeder` — populate subscription_plans table with 5 default plans *(requires DB access)*

**Checkpoint**: Admin can manage subscription plans via full CRUD UI.

---

## Phase 3: US2 — Subscription Renewal & Plan API Extensions (Priority: P1)

**Goal**: Users can renew subscriptions via API. Admin has toggle, sort, and country pricing endpoints.

**Independent Test**: POST `/api/subscription/renew`, POST `/api/admin/subscription-plans/1/toggle`, PUT `/api/admin/subscription-plans/sort`

### Implementation

- [X] T012 [US2] Add `renew()` method to `app/Http/Controllers/API/SubscriptionApiController.php` — validate auth, get active subscription, call `$this->subscriptionService->renewWithPayment()` with wallet split. Return subscription data with new `ends_at`.
- [X] T013 [US2] Register `POST /api/subscription/renew` route in `routes/api.php` inside the authenticated subscription group
- [X] T014 [US2] Add `toggle()` method to `app/Http/Controllers/Admin/SubscriptionPlanController.php` — find plan, flip `is_active`, save, return JSON `{success, data: {id, is_active}}`
- [X] T015 [US2] Add `updateSortOrder()` method to `app/Http/Controllers/Admin/SubscriptionPlanController.php` — validate array of `{id, sort_order}`, bulk update, return success JSON
- [X] T016 [US2] Add `setCountryPrices()` method to `app/Http/Controllers/Admin/SubscriptionPlanController.php` — validate array of `{country_code, price}`, use `SubscriptionPlanPrice::updateOrCreate()` for each, return success JSON
- [X] T017 [US2] Register admin plan management routes in `routes/web.php` — `POST /subscription-plans/{id}/toggle`, `PUT /subscription-plans/sort`, `POST /subscription-plans/{id}/country-prices`. Permission: `subscription-plans-edit`
- [X] T018 [P] [US2] Register API equivalents in `routes/api.php` — same 3 endpoints under `/api/admin/subscription-plans/` prefix with `auth:sanctum` middleware

**Checkpoint**: Subscription renewal API works. Admin can toggle, sort, and set country prices.

---

## Phase 4: US3 — Localized Pricing (Priority: P1)

**Goal**: API returns subscription plan prices in the user's local currency based on IP geolocation.

**Independent Test**: Call GET `/api/subscription/plans` from different IPs and verify `display_price`, `display_currency`, `display_symbol` fields.

### Implementation

- [X] T019 [US3] Modify `getPlans()` in `app/Http/Controllers/API/SubscriptionApiController.php` — inject `PricingService`, call `detectUserCountry()`, for each plan call `getPriceForCountry()`, append `display_price`, `display_currency`, `display_symbol` to response
- [X] T020 [US3] Add 'Country Prices' section to `resources/views/admin/subscription-plans/edit.blade.php` — below main form, table with rows per `SupportedCurrency` (active only): country name, currency code, price input. Pre-fill from `SubscriptionPlanPrice`. AJAX save button calls `setCountryPrices` endpoint.

**Checkpoint**: Public plan API returns localized prices. Admin can set per-country prices.

---

## Phase 5: US4 — Supervisor Role Management (Priority: P2)

**Goal**: Admin panel renames "Instructors" to "Supervisors" and provides granular permission assignment.

**Independent Test**: Open staff edit page, see permission checkboxes, assign permissions, verify access control on admin pages.

### Implementation

- [X] T021 [P] [US4] Rename "Instructors" to "Supervisors" in `resources/views/components/sidebar.blade.php` — change label text to `{{ __('المشرفين') }}` / `Supervisors`, keep route names and icon unchanged
- [X] T022 [P] [US4] Rename page titles in instructor-related Blade views — search `resources/views/admin/` for "Instructor" / "المدرب" text, replace with "Supervisor" / "المشرف". Keep file names and routes unchanged.
- [X] T023 [US4] Create permission assignment section in staff/user edit view — load all permissions grouped by prefix (e.g., `courses`, `users`, `subscriptions`). Render checkbox grid. On save, call `$user->syncPermissions($selectedPermissions)` via Spatie.
- [X] T024 [US4] Add permission middleware to admin controllers — `CoursesController`: `manage_courses`, `UserController`: `manage_accounts`, `WalletController`: `manage_finances`, `SubscriptionPlanController`: `manage_plans`. Use `ResponseService::noAnyPermission` in method bodies (project convention).

**Checkpoint**: Admin sees "Supervisors" in UI. Can assign granular permissions. Permission checks enforced on admin controllers.

---

## Phase 6: US5 — Content Approval Workflow (Priority: P2)

**Goal**: Ratings and comments require admin approval before being publicly visible. Admin has a pending queue to approve/reject.

**Independent Test**: Submit a rating, verify it's hidden from public API. Admin opens approvals page, approves it, verify it appears in public API.

### Implementation

- [X] T025 [US5] Modify `app/Http/Controllers/API/RatingApiController.php` — in listing method, when `FeatureFlagService::isEnabled('content_approval')`, chain `->where('status', 'approved')`. In store method, set `'status' => 'pending'` when flag is enabled.
- [X] T026 [P] [US5] Modify `app/Http/Controllers/API/CourseDiscussionApiController.php` — same pattern as T025: filter by approved in listing, default to pending in store when feature flag enabled.
- [X] T027 [US5] Create `app/Http/Controllers/Admin/ApprovalController.php` — 6 methods: `pendingRatings()` returns paginated ratings with status=pending + user + course; `approveRating($id)` sets status=approved, reviewed_by=auth, reviewed_at=now; `rejectRating($id)` sets status=rejected; `pendingComments()`, `approveComment($id)`, `rejectComment($id)` — same pattern for `CourseDiscussion` model. Use `ResponseService` for permission checks.
- [X] T028 [US5] Register approval admin routes in `routes/web.php` — inside admin group: `GET /approvals` → `ApprovalController::pendingRatings` (page), `POST /approvals/ratings/{id}/approve`, `POST /approvals/ratings/{id}/reject`, `GET /approvals/comments` → `pendingComments`, `POST /approvals/comments/{id}/approve`, `POST /approvals/comments/{id}/reject`. Permission: `approve_ratings`, `approve_comments`.
- [X] T029 [P] [US5] Register approval API routes in `routes/api.php` — `GET /api/admin/reviews/pending`, `POST /api/admin/reviews/{id}/approve`, `POST /api/admin/reviews/{id}/reject`, `GET /api/admin/comments/pending`, `POST /api/admin/comments/{id}/approve`, `POST /api/admin/comments/{id}/reject`
- [X] T030 [US5] Create `resources/views/admin/approvals/index.blade.php` — extends `layouts.app`, `$type_menu = 'approvals'`. Bootstrap tabs: "التقييمات" (Ratings) and "التعليقات" (Comments). Each tab: table with user, course, content, date, approve/reject buttons. Approve/reject via AJAX POST.
- [X] T031 [US5] Add "الموافقات" (Approvals) link to sidebar in `resources/views/components/sidebar.blade.php` — under appropriate section, icon `fas fa-check-circle`, `@canany('approve_comments', 'approve_ratings')`

**Checkpoint**: Ratings and comments go through approval. Admin can manage pending queue via UI.

---

## Phase 7: Polish & Verification

**Purpose**: Final integration testing across all features

- [X] T032 Verify feature flags toggle on/off via admin UI — confirm `content_approval` flag controls rating/comment visibility *(code verified: FeatureFlagService gating in RatingApiController and CourseDiscussionApiController)*
- [ ] T033 Verify subscription flow end-to-end — create plan → subscribe → commission generated → withdrawal available after 15 days *(requires running application)*
- [ ] T034 Verify pricing flow — detect country → display local price → Kashier checkout → subscription activated *(requires running application)*
- [ ] T035 Verify video progress flow — watch to 85% → lecture marked complete → 100% → certificate with QR generated *(requires running application)*
- [ ] T036 Verify notification commands — run `subscriptions:send-expiry-notifications` and `subscriptions:handle-expired` manually, check logs *(requires running application)*
- [ ] T037 Verify permission enforcement — log in as supervisor with limited permissions, confirm restricted pages return 403 *(requires running application)*
- [X] T038 Run linter check on all modified files — zero new errors introduced

**Checkpoint**: All 23 tasks verified. System is production-ready for these features.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Foundation)**: No dependencies — start immediately
- **Phase 2 (Plan UI)**: Depends on Phase 1 (seeder data needed)
- **Phase 3 (Plan API)**: Depends on Phase 1
- **Phase 4 (Pricing)**: Depends on Phase 1 + Phase 3 (needs routes)
- **Phase 5 (Supervisor)**: Independent — can run in parallel with Phases 2-4
- **Phase 6 (Approval)**: Independent — can run in parallel with Phases 2-5
- **Phase 7 (Verify)**: Depends on ALL above phases

### Parallel Opportunities

```
Phase 1 (Foundation)
  ↓
  ├── Phase 2 (Plan UI)     ──┐
  ├── Phase 3 (Plan API)    ──┤
  ├── Phase 5 (Supervisor)  ──┤── All can run in parallel
  └── Phase 6 (Approval)    ──┘
       ↓
  Phase 4 (Pricing) — after Phase 3
       ↓
  Phase 7 (Verify) — after all
```

### Within Each Phase

- Tasks marked [P] can run in parallel (different files)
- Routes registration after controller methods
- Views after controllers are ready
- Seeders before any dependent functionality

---

## Task Summary

| Phase | Tasks | Est. Time | Depends On |
|-------|-------|-----------|------------|
| 1 Foundation | T001–T005 | 1.5h | — |
| 2 Plan UI | T006–T011 | 6.75h | Phase 1 |
| 3 Plan API | T012–T018 | 3h | Phase 1 |
| 4 Pricing | T019–T020 | 3h | Phase 1, 3 |
| 5 Supervisor | T021–T024 | 3.25h | Independent |
| 6 Approval | T025–T031 | 4.5h | Independent |
| 7 Verify | T032–T038 | 2h | All |
| **Total** | **38 subtasks** | **24h** | |
