# Implementation Plan: LMS (Master — Aligned with tasks.md)

**Branch**: `apply-pricing-system` (update if different) | **Date**: 2026-04-29 | **Spec**: [specs/main/spec.md](spec.md) | **Tasks**: [specs/main/tasks.md](tasks.md)

## Summary

Single source of truth for execution order is **`specs/main/tasks.md`** (62 tasks, 11 phases). The older “23 tasks” narrative in `spec.md` describes the **same feature areas** but uses **legacy ClickUp-style IDs** (T008–T098); treat `tasks.md` as authoritative for IDs, checkpoints, and completion checkboxes.

**Current state (as of tasks.md):** Most implementation tasks are marked complete (code paths, controllers, views, routes). **Remaining work is mainly operational:** run migrations/seeders where a database is available, run subscription plan seeder, and **manual end-to-end verification** (pricing, Kashier, affiliate commission cycle, video progress → certificate, scheduled commands, permission spot-checks).

**Estimated residual effort:** ~2–4 hours of DBA/runtime verification (plus any bugs found), not the original ~45h full build.

## Technical Context

| Item | Value |
|------|--------|
| Language / framework | PHP 8.3 / Laravel 12 |
| Key packages | Spatie laravel-permission, endroid/qr-code, Kashier, Mpdf |
| Storage | MySQL 8.x, Eloquent |
| Testing | PHPUnit; many flows verified manually per tasks |
| App shape | Laravel monolith: Blade admin + REST API |
| Product constraints | Arabic-first, EGP base, Spatie permissions, existing admin layout |

## Constitution / Conventions Check

| Gate | Status |
|------|--------|
| Laravel conventions | Pass — services, thin controllers where applied |
| Arabic-first | Pass — `__()` in admin strings |
| Security | Pass — permissions on admin routes |
| Simplicity | Pass — extends existing patterns |
| No unnecessary breaking changes | Pass — additive work |

## Documentation Map

```text
specs/main/
├── plan.md              # This file — master execution overview
├── spec.md              # Feature narrative (legacy task IDs T008+)
├── tasks.md             # Authoritative checklist (T001–T038) + phases 8–11
├── plan_v2-summary.md   # Arabic summary: 6-phase v2 vs tasks.csv
├── research.md
├── data-model.md
├── quickstart.md
└── contracts/
    ├── admin-plans-api.md
    ├── approval-api.md
    ├── content-access-api.md
    ├── affiliate-api.md
    └── certificate-api.md
```

**Related:** `.agent/memory-bank/implementation_plan_v2.md`, `.agent/memory-bank/clickup-import-tasks.csv` (historical ID scheme).

## Source Code Anchor (repository root)

High-touch areas from the plan (not exhaustive):

```text
app/Http/Controllers/Admin/SubscriptionPlanController.php
app/Http/Controllers/Admin/ApprovalController.php
app/Http/Controllers/API/SubscriptionApiController.php
app/Services/PricingService.php
app/Services/SubscriptionService.php
resources/views/admin/subscription-plans/
resources/views/admin/approvals/
resources/views/components/sidebar.blade.php
routes/web.php
routes/api.php
database/seeders/
```

## Execution Phases (aligned with tasks.md)

Phases below mirror **`specs/main/tasks.md`**. Use that file for checkboxes and file-level paths.

| Phase | Name | Task range | Purpose |
|-------|------|------------|---------|
| 1 | Foundation | T001–T005 | Migrations, currency seeder, role/permission seeder, migration status |
| 2 | US1 Plan admin UI | T006–T011 | Sidebar, index/edit/show, SubscriptionPlanSeeder, run seeder |
| 3 | US2 Renew + plan API | T012–T018 | `renew`, toggle, sort, country prices, web + API routes |
| 4 | US3 Localized pricing | T019–T020 | `getPlans()` + admin country prices UI |
| 5 | US4 Supervisor | T021–T024 | Rename to Supervisors, permission UI, controller checks |
| 6 | US5 Approval | T025–T031 | API filters, ApprovalController, routes, Blade UI, sidebar |
| 8 | US6 Content access | T039–T047 | Progress, free flags, attachments, enforcement |
| 9 | US7 Affiliate | T048–T056 | Tables, models, service, APIs, hooks, release command |
| 10 | US8 Notifications & certs | T057–T062 | Expiry commands, mail, certificate QR, verify route |
| 11 | Polish & verification | T032–T038 | Feature-flag check, E2E flows, linter |

**Note:** Phase numbering in `tasks.md` skips “7” by design (historical); do not renumber without updating all references.

## Parallelism & Dependencies

- **After Phase 1:** Phases 2, 3, 5, 6, 8 can proceed in parallel where no shared files conflict; Phase 4 (pricing UI/API) logically follows Phase 3 wiring.
- **Phase 10 (notifications/certs)** depends on subscription + progress story for “100% completion” certificate rule — see `tasks.md` dependency diagram.
- **Phase 11** runs last.

## Plan v2 (six macro phases) vs this plan

`plan_v2-summary.md` maps **six product phases** (subscriptions → content → affiliate → wallet/pricing → admin → notifications/certs) to **tasks.md** and optional ClickUp CSV. That view is for **stakeholder alignment**; day-to-day execution should still follow **`tasks.md`** phase order to reduce merge conflict and respect DB dependencies.

## Remaining Work Checklist (operational)

| Item | Tasks | Action |
|------|-------|--------|
| DB apply + seed | T002–T005, T011 | `migrate`, `db:seed` for currencies, roles, subscription plans |
| Manual E2E | T033–T037 | App running: pricing/Kashier, affiliate cycle, video→cert, artisan commands, permission matrix |

## Generated / Living Artifacts

| Artifact | Path |
|----------|------|
| Authoritative tasks | `specs/main/tasks.md` |
| API contracts | `specs/main/contracts/*.md` |
| Detailed bilingual plan (Arabic detail) | `docs/superpowers/specs/2026-04-29-lms-master-plan-detailed.md` |

## Complexity Tracking

No exception requests; complexity remains in integration verification and payment webhooks, not in new abstractions for this track.
