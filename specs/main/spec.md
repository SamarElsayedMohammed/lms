# Feature Specification: LMS Remaining Tasks (23 Tasks)

**Date**: 2026-02-18 | **Priority**: High | **Est. Time**: 25.25 hours

## Overview

Complete all remaining tasks across 6 phases of the LMS subscription and feature system. These tasks cover admin UI views, localized pricing API, supervisor role management, content approval workflows, plan management API extensions, and final integration verification.

## Remaining Tasks by Phase

### Phase 1 — Subscription Plans Admin UI (7 tasks, 7.75h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T008 | Create Subscription Plans Index View | 2h | `index.blade.php`: inline create form (name, cycle, custom days, price, commission, features). Table with toggle, actions. AJAX create/edit/delete. |
| T009 | Create Plan Edit Modal/Form | 1.5h | `edit.blade.php`: Pre-filled form, dynamic features list, show/hide custom days by billing_cycle. |
| T010 | Create Plan Show/Details View | 1.5h | `show.blade.php`: Plan card, stats (subscribers, revenue), subscribers table with pagination. |
| T011 | Add Subscriptions Section to Sidebar | 0.5h | New header 'الاشتراكات'. Menu item → subscription-plans.index. Icon fas fa-gem. `@can('subscription-plans-list')`. |
| T012 | Create SubscriptionPlanSeeder | 1h | 5 plans: Monthly (100 EGP/10%), Quarterly (270/12%), Semi-Annual (500/15%), Yearly (900/20%), Lifetime (2500/25%). Features JSON. |
| T013 | Run SubscriptionPlanSeeder | 0.25h | Execute `php artisan db:seed --class=SubscriptionPlanSeeder`. |
| T014 | Add Renew Method to SubscriptionApiController | 1h | `renew()` method. POST /api/subscription/renew. Validate and process renewal. |

### Phase 8 — Localized Pricing (4 tasks, 4h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T051 | Modify Subscription API for Localized Prices | 1h | In `getPlans()` detect country, return `display_price`, `display_currency`, `display_symbol` per plan via PricingService. |
| T052 | Create SupportedCurrencySeeder | 0.75h | Seed EG (EGP), SA (SAR), AE (AED), US (USD) with initial exchange rates. |
| T053 | Add Admin UI for Country Pricing | 2h | On plan edit page add 'Country Prices' section: country, currency, price input. Save via AJAX. |
| T054 | Run Pricing Migrations and Seeder | 0.25h | Execute migrate + db:seed for pricing tables. |

### Phase 10 — Supervisor Role Management (4 tasks, 3.5h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T062 | Rename Instructor to Supervisor in UI | 0.75h | Update sidebar, views, page titles. 'Instructors' → 'Supervisors' / 'المشرفين'. Keep DB/route names. |
| T063 | Create Admin UI for Role Permission Assignment | 1.5h | Permission checkboxes in staff/user edit view. Save via Spatie `assignRole()` / `givePermissionTo()`. |
| T064 | Add Permission Checks to Admin Controllers | 1h | Middleware/can checks: CoursesController→manage_courses, UserController→manage_accounts, etc. |
| T065 | Run Permissions Seeder | 0.25h | Execute `php artisan db:seed --class=RolePermissionSeeder`. |

### Phase 11 — Content Approval Workflow (5 tasks, 4.75h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T069 | Modify Public API to Filter by Approval | 1h | In RatingApiController/CourseDiscussionApiController: when feature flag enabled, filter by `->approved()`. New submissions default to pending. |
| T070 | Create Admin ApprovalController | 1.5h | Methods: pendingRatings/approveRating/rejectRating/pendingComments/approveComment/rejectComment. |
| T071 | Register Approval Admin Routes | 0.5h | GET/POST /api/admin/reviews/pending, approve, reject. Same for comments. Permission: approve_comments, approve_ratings. |
| T072 | Create Admin Approval UI | 1.5h | `admin/approvals/index.blade.php` with tabs for Ratings and Comments. Approve/Reject buttons. Sidebar link. |
| T073 | Run Approval Migration | 0.25h | Execute `php artisan migrate` for approval fields. |

### Phase 13 — Plan API Extensions (2 tasks, 2h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T081 | Extend SubscriptionPlanController for Full CRUD API | 1.5h | Add `toggle()`, `updateSortOrder()`, `setCountryPrices()`. Verify full CRUD. |
| T082 | Register Admin Plan Management Routes | 0.5h | POST toggle, PUT sort, POST prices. API: `/api/admin/subscription-plans/{id}/toggle`, etc. |

### Phase 17 — Final Integration (1 task, 2h)

| ID | Task | Time | Description |
|----|------|------|-------------|
| T098 | Final Integration Verification | 2h | Verify: feature flags, subscription→commission→withdrawal, pricing→Kashier→activation, 85% progress→certificate, notification commands. |

## Business Rules

1. All admin views extend `layouts.app` and use `$type_menu` for sidebar active state
2. Permission checks use `ResponseService::noAnyPermissionThenRedirect()` or `ResponseService::noPermissionThenSendJson()`
3. Seeders must be idempotent using `firstOrCreate`
4. AJAX endpoints return `{success: bool, message: string}`
5. Arabic is the default UI language; all strings wrapped in `__()`
6. Subscription plans support 6 billing cycles: monthly, quarterly, semi_annual, yearly, lifetime, custom
7. Country pricing displays in local currency but stores/charges in EGP

## Acceptance Criteria

- All 23 tasks complete and verified
- No linter errors introduced
- Migrations run cleanly on fresh DB
- Admin UI accessible with proper permissions
- API endpoints return correct JSON structure
- Feature flags control approval workflow visibility
