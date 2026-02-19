# Phase 1 Tasks: Admin Subscription Plans Management

> **Feature:** Subscription Plan CRUD for Admin Dashboard
> **Total Tasks:** 14
> **Parallel Opportunities:** 4 groups
> **Dependencies:** Subscription models & migrations (already complete)

---

## Phase 1: Setup (Foundation)

- [x] T001 Create migration to add `commission_rate` column to `subscription_plans` table in `database/migrations/2026_02_15_000001_add_commission_rate_to_subscription_plans_table.php`
- [x] T002 Update `SubscriptionPlan` model to add `commission_rate` to fillable and casts in `app/Models/SubscriptionPlan.php`
- [x] T003 Run migration `php artisan migrate`

---

## Phase 2: Permissions & Routing

- [x] T004 Create permission seeder for `subscription-plans-list`, `subscription-plans-create`, `subscription-plans-edit`, `subscription-plans-delete` in `database/seeders/RolePermissionSeeder.php` (added to existing seeder)
- [x] T005 Run permission seeder and assign to admin role
- [x] T006 Add subscription plan resource routes to admin group in `routes/web.php`

---

## Phase 3: Admin Controller

- [x] T007 Create `SubscriptionPlanController` with full CRUD (index, create, store, edit, update, show, destroy, restore, toggle) in `app/Http/Controllers/Admin/SubscriptionPlanController.php`
  - `index()` — List all plans with subscriber count, sortable, filterable
  - `create()` — Show create form
  - `store()` — Validate and save new plan (slug auto-generated)
  - `edit($id)` — Show edit form with current data
  - `update($id)` — Validate and update plan
  - `show($id)` — Plan details + list of subscribed users
  - `destroy($id)` — Soft delete plan
  - `restore($id)` — Restore soft-deleted plan
  - `toggleStatus($id)` — Toggle `is_active` on/off via AJAX

---

## Phase 4: Admin Views (Parallelizable)

- [ ] T008 [P] Create plans index view with create form + data table in `resources/views/admin/subscription-plans/index.blade.php`
  - Top: Inline create form (name, cycle, custom days, price, commission rate, features, sort order)
  - Bottom: Table listing all plans (name, price, cycle, subscribers count, commission %, status toggle, actions)
  - AJAX-based create/edit/delete following existing promo-codes pattern
  - Active/Inactive toggle inline via AJAX

- [ ] T009 [P] Create plan edit modal or form in `resources/views/admin/subscription-plans/edit.blade.php`
  - Pre-filled form with all plan fields
  - Dynamic features list (add/remove feature items)
  - Show/hide custom days field based on billing_cycle selection

- [ ] T010 [P] Create plan show/details view in `resources/views/admin/subscription-plans/show.blade.php`
  - Plan summary card (name, price, cycle, features, commission rate, status)
  - Statistics row (total subscribers, active subscribers, revenue estimate)
  - Subscribers table (user name, email, start date, end date, status, auto_renew)
  - Pagination for subscribers list

---

## Phase 5: Sidebar & Navigation

- [ ] T011 Add "الاشتراكات" (Subscriptions) section to sidebar in `resources/views/components/sidebar.blade.php`
  - New menu header: "الاشتراكات"
  - Menu item: "باقات الاشتراك" → `subscription-plans.index` route
  - Permission-gated: `@can('subscription-plans-list')`
  - Icon: `fas fa-gem` or `fas fa-crown`
  - Active state: `$type_menu === 'subscription-plans'`

---

## Phase 6: Default Data

- [ ] T012 Create seeder for 5 default subscription plans in `database/seeders/SubscriptionPlanSeeder.php`
  - Monthly: شهري, 100 EGP, 30 days, 10% commission
  - Quarterly: ربع سنوي, 270 EGP, 90 days, 12% commission
  - Semi-Annual: نصف سنوي, 500 EGP, 180 days, 15% commission
  - Yearly: سنوي, 900 EGP, 365 days, 20% commission
  - Lifetime: مدى الحياة, 2500 EGP, null days, 25% commission
  - Each plan includes features JSON array

- [ ] T013 Run seeder `php artisan db:seed --class=SubscriptionPlanSeeder`

---

## Phase 7: API Completion

- [ ] T014 Add `renew` method to `SubscriptionApiController` and register route `POST /api/subscription/renew` in `app/Http/Controllers/API/SubscriptionApiController.php` and `routes/api.php`

---

## Dependency Graph

```
T001 → T002 → T003 (migration must run first)
T004 → T005 (permissions before seeding)
T003 + T005 → T006 (routes need model + permissions)
T006 → T007 (controller needs routes)
T007 → T008, T009, T010 (views need controller — parallelizable)
T007 → T011 (sidebar needs routes)
T003 → T012 → T013 (seeder needs migration)
T007 → T014 (API renew can be parallel with views)
```

## Execution Order (Optimal)

```
Sequential: T001 → T002 → T003
Sequential: T004 → T005
Sequential: T006 → T007
Parallel:   T008 | T009 | T010 | T011 | T014
Sequential: T012 → T013
```

**Estimated time:** 4-5 hours

---

## Acceptance Criteria

1. ✅ Admin can see "باقات الاشتراك" in sidebar
2. ✅ Admin can create a new subscription plan with all fields
3. ✅ Admin can edit any existing plan
4. ✅ Admin can toggle plan active/inactive inline
5. ✅ Admin can view plan details with subscriber list
6. ✅ Admin can soft-delete and restore plans
7. ✅ 5 default plans are seeded
8. ✅ Commission rate is stored per plan
9. ✅ Permission-gated (non-admins cannot access)
10. ✅ All labels in Arabic with `__()` helper
11. ✅ `POST /api/subscription/renew` endpoint works
