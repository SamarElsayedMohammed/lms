# LMS v1.0.2 Constitution

## Core Principles

### I. Laravel Conventions First
All code follows Laravel 12 / PHP 8.3 conventions: Eloquent ORM, Form Requests, Service classes for business logic, Blade views for admin UI, API resources for JSON responses. No custom frameworks or ORMs.

### II. Arabic-First Bilingual
All user-facing strings use `__()` translation helper. Arabic is the primary language. Admin panel supports RTL. Database stores language-neutral data.

### III. Existing Architecture Respect
New features extend the existing codebase patterns — `ResponseService` for permission checks, `ApiResponseService` for API responses, Spatie for roles/permissions, existing admin layout (`layouts.app`). No parallel architectures.

### IV. Security by Default
All admin routes require authentication + permission middleware. All API routes require `auth:sanctum`. CSRF protection for web routes. Input validation on every endpoint. No raw SQL without parameterization.

### V. Simplicity & YAGNI
Start with the simplest working solution. No premature abstractions. No repository pattern — use Eloquent directly or thin Service classes. Feature flags to toggle new features without deployment.

## Additional Constraints

- **Payment**: Kashier gateway only. All amounts stored in EGP. Display currency converted via `exchange_rate_to_egp`.
- **Permissions**: Spatie `laravel-permission`. All new admin features must have corresponding permissions in `RolePermissionSeeder`.
- **Caching**: Use `Cache::remember()` for expensive queries. TTL 5-60 min depending on volatility.
- **No Breaking Changes**: Existing API endpoints and DB schemas must remain backward compatible. Use migrations for schema changes.

## Development Workflow

- Migrations before seeders. Seeders are idempotent (`firstOrCreate`).
- Admin views extend `layouts.app` and set `$type_menu` for sidebar highlighting.
- AJAX endpoints return `{success: bool, message: string, data?: any}`.
- All admin CRUD follows pattern: index/store/update/destroy with permission checks.

## Governance

Constitution supersedes ad-hoc decisions. All new features must pass the Constitution Check in the implementation plan before proceeding.

**Version**: 1.0 | **Ratified**: 2026-02-15 | **Last Amended**: 2026-02-18
