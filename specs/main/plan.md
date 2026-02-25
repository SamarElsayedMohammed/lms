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
├── research.md          # Phase 0: research (+ R7–R11 remaining plan v2 phases)
├── data-model.md        # Phase 1: entity model (+ Phase 2, 3, 6 entities)
├── quickstart.md        # Phase 1: implementation guide (+ Steps 8–12 plan v2)
├── contracts/
│   ├── admin-plans-api.md   # Plan CRUD + extensions
│   ├── approval-api.md     # Content approval workflow
│   ├── content-access-api.md # Video progress, attachments (plan v2 Phase 2)
│   ├── affiliate-api.md    # Affiliate system (plan v2 Phase 3)
│   └── certificate-api.md  # Certificate verification (plan v2 Phase 6)
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

## Remaining Plan v2 Phases (Design Complete)

Design artifacts for **all rest phases** of implementation plan v2 (Phases 2–6) have been added:

| Phase | Description | Artifacts |
|-------|-------------|-----------|
| Phase 2 | Content Access, Video Progress 85%, Free courses/lessons, Lecture attachments, Feature flags | research.md R7–R8, data-model.md (LectureProgress, LectureAttachment, FeatureFlag), content-access-api.md, quickstart Step 8 |
| Phase 3 | Affiliate system (one-time commission, bi-monthly release, withdrawals) | research.md R9, data-model.md (AffiliateLink, AffiliateCommission, AffiliateWithdrawal), affiliate-api.md, quickstart Step 9 |
| Phase 4 | Wallet & Kashier | research.md R10, quickstart Step 10 |
| Phase 5 | Admin, roles, marketing pixels | Covered by existing tasks + quickstart Step 11 |
| Phase 6 | Notifications (7d, 3d, 24h), Certificates (100% + QR, verify) | research.md R11, certificate-api.md, quickstart Step 12 |

Tasks for these phases can be broken down via `/speckit.tasks` or from `.agent/memory-bank/clickup-import-tasks.csv`.

## Generated Artifacts

| Artifact | Path | Phase |
|----------|------|-------|
| Feature Spec | `specs/main/spec.md` | Input |
| Constitution | `.specify/memory/constitution.md` | Input |
| Research | `specs/main/research.md` | Phase 0 (+ R7–R11 remaining phases) |
| Data Model | `specs/main/data-model.md` | Phase 1 (+ Phase 2, 3, 6 entities) |
| Plan API Contract | `specs/main/contracts/admin-plans-api.md` | Phase 1 |
| Approval API Contract | `specs/main/contracts/approval-api.md` | Phase 1 |
| Content Access API Contract | `specs/main/contracts/content-access-api.md` | Phase 1 (plan v2 Phase 2) |
| Affiliate API Contract | `specs/main/contracts/affiliate-api.md` | Phase 1 (plan v2 Phase 3) |
| Certificate API Contract | `specs/main/contracts/certificate-api.md` | Phase 1 (plan v2 Phase 6) |
| Quickstart | `specs/main/quickstart.md` | Phase 1 (+ Steps 8–12) |
| Implementation Plan | `specs/main/plan.md` | Phase 1 |
