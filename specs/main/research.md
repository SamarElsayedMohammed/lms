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

## R7: Video Progress & Content Access (Plan v2 Phase 2)

**Decision**: New table for per-lecture progress (e.g. `lecture_progress` or extend `user_curriculum_tracking`) with `watched_seconds`, `total_seconds`, `last_position`, `watch_percentage`, `is_completed`. `VideoProgressService` (or `ContentAccessService`) provides `updateProgress`, `getProgress`, `canAccessNextLesson`, `getCourseProgress`. Access rule: login required; then course/lecture free OR active subscription; then previous lesson ≥ 85% OR first lesson.
**Rationale**: Implementation plan v2 mandates 85% watch before next lesson unlock. Hybrid anti-cheat: client sends progress periodically; server can issue random challenges (optional later).
**Alternatives considered**: Client-only progress — rejected (no server validation). Full server-side playback verification — deferred (complexity).

## R8: Free Courses/Lessons & Feature Flags (Plan v2 Phase 2)

**Decision**: Add `is_free`, `is_free_until` to courses; `is_free` to course_chapter_lectures. Feature flags table (or settings) for `lecture_attachments`, `affiliate_system`, `video_progress_enforcement`, `comments_require_approval`, `ratings_require_approval`. Lecture attachments behind `feature_lecture_attachments_enabled` (admin toggle, default false).
**Rationale**: Matches plan v2. Existing codebase has feature-flag / settings patterns; reuse them.
**Alternatives considered**: Separate “free tier” product — rejected (spec says free courses/lessons only).

## R9: Affiliate System (Plan v2 Phase 3)

**Decision**: Tables `affiliate_links`, `affiliate_commissions`, `affiliate_withdrawals`, and settings for `min_withdrawal_amount`, `is_enabled`. One-time commission on first subscription payment only; bi-monthly release (1–15 → available 28th; 16–end → available 15th next month). When disabled, affiliate routes return 404 or equivalent; no commission calculation.
**Rationale**: Plan v2 specifies one-time commission, 500 EGP minimum, 15-day settlement. Feature toggle required.
**Alternatives considered**: Recurring commission — rejected (spec: one-time only).

## R10: Kashier & Wallet (Plan v2 Phase 4)

**Decision**: `KashierCheckoutService` for createCheckoutSession, verifyPayment, webhook handler. Subscribe flow: deduct wallet first, then Kashier for remainder. Wallet top-up via Kashier. Credentials from settings (kashier_merchant_id, kashier_api_key, kashier_webhook_secret).
**Rationale**: Constitution mandates Kashier; EGP base. Existing WalletService; extend subscribe flow.
**Alternatives considered**: Wallet-only or Kashier-only — rejected (spec: wallet + gateway).

## R11: Notifications & Certificates (Plan v2 Phase 6)

**Decision**: Artisan commands: `subscriptions:send-expiry-notifications` (7d, 3d, 24h; push + email; set `notified_*_days`), `subscriptions:handle-expired` (mark expired / attempt auto-renew). Certificate: require 100% course progress (all lessons ≥ 85%); QR code (e.g. simplesoftwareio/simple-qrcode) with verification URL; public `GET /certificate/verify/{number}`.
**Rationale**: Plan v2: 3-tier expiry notifications; no grace period; certificate only on 100% completion with QR.
**Alternatives considered**: Grace period — rejected (spec: no grace). Certificate without 100% gate — rejected (spec: 100% required).

## All NEEDS CLARIFICATION Resolved

No unresolved unknowns remain. All technical decisions are based on existing codebase patterns and the implementation plan v2.
