# Research: LMS Remaining Tasks

**Phase**: 0 — Research | **Date**: 2026-02-18

## R1: Admin Blade View Patterns (Phase 1, 10, 11)

**Decision**: Follow existing admin view patterns in the codebase.
**Rationale**: The project already has established patterns for admin CRUD views (e.g., `admin/currencies/index.blade.php`, `admin/feature-flags/index.blade.php`). All views extend `layouts.app`, use Bootstrap 4 card components, and set `$type_menu` for sidebar highlighting.
**Alternatives considered**: Vue.js SPA components — rejected because the existing admin panel is Blade-based and consistency is required.

### Pattern Reference
```
@extends('layouts.app')
@section('title', __('Page Title'))
@section('main')
  <div class="row"><div class="col-md-12"><div class="card"><div class="card-body">
    {{-- Content --}}
  </div></div></div></div>
@endsection
```

## R2: Subscription Plan CRUD (Phase 1, 13)

**Decision**: Use `SubscriptionPlanController` (already exists) with additional admin routes. Index view has inline create form + data table. Edit uses separate page.
**Rationale**: Matches the existing `CurrencyController` CRUD pattern. The `SubscriptionPlanController` already has `index`, `create`, `store`, `edit`, `update`, `destroy` methods. Need to add `toggle`, `updateSortOrder`, `setCountryPrices`.
**Alternatives considered**: Modal-based editing — rejected for simplicity with complex forms (features JSON, country prices).

## R3: Localized Pricing Display (Phase 8)

**Decision**: `PricingService::getPriceForCountry()` detects user country via IP using `GeoLocationService`, looks up `SubscriptionPlanPrice` for that country, falls back to base EGP price with currency conversion.
**Rationale**: Models and service already exist (T048-T050 complete). Just need to wire into the API response.
**Alternatives considered**: Client-side currency conversion — rejected because rates change and server controls pricing.

### API Response Enhancement
```json
{
  "plans": [{
    "id": 1,
    "name": "شهري",
    "price": 100,
    "display_price": "3.67",
    "display_currency": "AED",
    "display_symbol": "د.إ"
  }]
}
```

## R4: Permission Assignment UI (Phase 10)

**Decision**: Checkboxes grouped by category on user edit page. Uses Spatie `syncPermissions()` for atomic updates.
**Rationale**: Spatie is already integrated. The `RolePermissionSeeder` generates permissions by prefix (e.g., `feature-flags-list`, `feature-flags-edit`). UI groups them by prefix.
**Alternatives considered**: Role-only assignment — rejected because spec requires granular per-permission control.

## R5: Content Approval Workflow (Phase 11)

**Decision**: Feature-flag-gated workflow. When `content_approval` flag is enabled, new ratings/comments default to `status=pending`. Public API filters by `->approved()`. Admin sees pending queue.
**Rationale**: Migration and model updates already done (T066-T068 complete). Need controller, routes, UI, and API filter integration.
**Alternatives considered**: Separate moderation queue service — rejected as over-engineering for the current scale.

## R6: Sidebar Navigation Structure

**Decision**: Add "الاشتراكات" (Subscriptions) section and "الموافقات" (Approvals) link to admin sidebar.
**Rationale**: Sidebar is in `resources/views/components/sidebar.blade.php`. Uses `@can` / `@canany` for permission gating. Each item checks `$type_menu` for active state.
**Alternatives considered**: None — sidebar is the only navigation mechanism.

## All NEEDS CLARIFICATION Resolved

No unresolved unknowns remain. All technical decisions are based on existing codebase patterns and the implementation plan v2.
