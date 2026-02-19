# Implementation Plan: LMS Remaining 23 Tasks

**Branch**: `fixIOSNotification` | **Date**: 2026-02-18 | **Spec**: [specs/main/spec.md](spec.md)
**Input**: Feature specification from `/specs/main/spec.md`

## Summary

Complete all 23 remaining tasks across 6 phases of the LMS project: admin UI for subscription plans, localized pricing API, supervisor role management, content approval workflows, plan API extensions, and final integration verification. Total estimated effort: 25.25 hours.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 12
**Primary Dependencies**: Spatie laravel-permission, endroid/qr-code v6, Kashier gateway, Mpdf
**Storage**: MySQL 8.x via Eloquent ORM
**Testing**: PHPUnit (manual verification for admin UI)
**Target Platform**: Linux server, web browser (admin panel)
**Project Type**: Web application (Laravel monolith — Blade admin + REST API)
**Performance Goals**: Admin pages < 500ms, API responses < 200ms
**Constraints**: Arabic-first bilingual, EGP base currency, Spatie permissions, existing admin layout
**Scale/Scope**: ~60 admin views, ~80 API endpoints, ~40 models

## Constitution Check

*GATE: ✅ PASS — All gates satisfied*

| Gate | Status | Notes |
|------|--------|-------|
| Laravel conventions | ✅ | Eloquent, Blade, Service pattern |
| Arabic-first | ✅ | All strings use `__()` |
| Existing architecture | ✅ | Extends existing patterns |
| Security | ✅ | Permission middleware on all admin routes |
| Simplicity | ✅ | No new abstractions, extends existing code |
| Spatie permissions | ✅ | All new features have seeded permissions |
| No breaking changes | ✅ | Only additive changes |

## Project Structure

### Documentation (this feature)

```text
specs/main/
├── plan.md              # This file
├── spec.md              # Feature specification (23 remaining tasks)
├── research.md          # Phase 0: research findings
├── data-model.md        # Phase 1: entity model
├── quickstart.md        # Phase 1: implementation guide
├── contracts/
│   ├── admin-plans-api.md   # Plan CRUD + extensions
│   └── approval-api.md      # Content approval workflow
└── tasks.md             # Phase 2 output (via /speckit.tasks)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   ├── Admin/
│   │   ├── SubscriptionPlanController.php   # T008-T010, T081
│   │   └── ApprovalController.php           # T070 (NEW)
│   └── API/
│       └── SubscriptionApiController.php    # T014, T051
├── Models/                                  # No new models needed
├── Services/
│   └── PricingService.php                   # T051 (wire into API)
database/
├── seeders/
│   ├── SubscriptionPlanSeeder.php           # T012 (modify)
│   └── SupportedCurrencySeeder.php          # T052 (NEW)
resources/views/admin/
├── subscription-plans/
│   ├── index.blade.php                      # T008
│   ├── edit.blade.php                       # T009, T053
│   └── show.blade.php                       # T010
└── approvals/
    └── index.blade.php                      # T072 (NEW)
routes/
├── web.php                                  # T071, T082
└── api.php                                  # T014, T082
```

**Structure Decision**: Extend existing Laravel monolith structure. No new directories or architectural patterns. All new files follow established naming conventions.

## Complexity Tracking

No constitution violations. No complexity justification needed.

## Execution Phases

### Batch A: Foundation (T052, T054, T065, T073) — 1.5h
Seeders and migration verification. Must run first.

### Batch B: Admin Plan UI (T008-T013) — 6.75h
Subscription plan CRUD views. Depends on Batch A.

### Batch C: Pricing + Plan API (T014, T051, T053, T081, T082) — 6h
API enhancements and country pricing UI. Depends on Batch A.

### Batch D: Supervisor (T062-T064) — 3.25h
UI rename and permission middleware. Independent.

### Batch E: Approval (T069-T072) — 4.5h
Approval workflow UI and API. Independent.

### Batch F: Verify (T098) — 2h
End-to-end verification. Depends on all above.

## Generated Artifacts

| Artifact | Path | Phase |
|----------|------|-------|
| Feature Spec | `specs/main/spec.md` | Input |
| Constitution | `.specify/memory/constitution.md` | Input |
| Research | `specs/main/research.md` | Phase 0 |
| Data Model | `specs/main/data-model.md` | Phase 1 |
| Plan API Contract | `specs/main/contracts/admin-plans-api.md` | Phase 1 |
| Approval API Contract | `specs/main/contracts/approval-api.md` | Phase 1 |
| Quickstart | `specs/main/quickstart.md` | Phase 1 |
| Implementation Plan | `specs/main/plan.md` | Phase 1 |
