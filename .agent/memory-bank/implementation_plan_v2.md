# Unified Implementation Plan v2: LMS Subscription & Feature System

> **Created:** 2026-02-08 | **Updated:** 2026-02-15 (Unified & Corrected)
> **Estimated Effort:** 80-100 hours
> **Status:** Phase 1 — In Progress (with fixes applied)

---

## Goal

Build a subscription-based access model for the LMS with:
- 6 subscription plan types (monthly, quarterly, semi-annual, yearly, lifetime, custom days)
- Per-country pricing (Udemy-style — each country sees its own currency)
- 85% video watch enforcement before unlocking next lesson
- Supervisor roles with granular permissions (replacing "Instructors")
- Affiliate/referral program with one-time commissions
- Marketing analytics integration (7 platforms)
- Admin approval for comments/ratings
- Certificate with QR/barcode on 100% course completion
- 3-tier expiry notifications (7d, 3d, 24h) via push + email
- Feature flag system for admin to toggle features on/off

---

## Key Decisions (User Requirements)

| Decision | Answer |
|----------|--------|
| Grace period after expiry | **NO** — access ends immediately |
| Trial / free plan | **NO** — but individual courses/lessons can be marked free |
| Auto-renewal default | **YES** — enabled by default |
| Backward compatibility (old purchases) | **NO** — subscription only (no previous purchases) |
| Payment currency | **Egyptian Pounds (EGP)** — display in local currency per country |
| Payment gateway | **Kashier** (Egyptian payment gateway) |
| Notification thresholds | Fixed: **7 days, 3 days, 24 hours** before expiry |
| Notifications type | **Push (FCM) + Email** |
| Affiliate commission | **One-time** per referred user (no renewal commission) |
| Affiliate settlement | Every **15 days** (1-15 settles end of month, 16-end settles 15th next month) |
| Minimum withdrawal | **500 EGP** |
| Comments/ratings | **Admin approval required** before visible |
| Instructor → Supervisor | Rename to **مشرفين (Supervisors)** with granular permissions |
| File attachments under videos | **Admin toggle** — initially hidden |
| Certificate requirement | **100% course completion** (all lessons at 85%+ watch) |
| Certificate barcode | **QR code** linking to verification URL |
| Login required | **YES** — for all content (even free) |

---

## Clarifications

### Session 2026-02-15

- Q: Video Progress Tracking - Anti-Cheating Strategy → A: Hybrid: Client tracks + periodic server challenge - random server challenges ask client to prove current playback state
- Q: Affiliate Commission Calculation Basis → A: Admin-controlled commission rates per package; Commission only on first payment; Admin-configurable minimum withdrawal; Bi-monthly calculation cycle (1-15 → day 28, 16-end → day 15 next month); Commissions locked until release date; Admin feature toggle (initially disabled)
- Q: Payment Gateway Selection for Egyptian Pounds → A: Kashier
- Q: Certificate QR Code Package → A: simplesoftwareio/simple-qrcode
- Q: Marketing Pixel Injection Strategy → A: Backend API serves active pixels, frontend dynamically injects during app initialization

---

## Phases Overview

| Phase | Description | Status | Est. Hours |
|-------|-------------|--------|------------|
| 1 | Subscription System | ✅ In Progress (fixes applied) | 15-18h |
| 2 | Content Access Control | 🔲 Not Started | 18-22h |
| 3 | Affiliate System | 🔲 Not Started | 12-15h |
| 4 | Wallet & Pricing | 🔲 Not Started | 10-12h |
| 5 | Admin, Roles & Reports | 🔲 Not Started | 15-18h |
| 6 | Notifications & Certificates | 🔲 Not Started | 10-15h |

---

## Phase 1: Subscription System ✅ (In Progress)

### What's Done
- ✅ `SubscriptionPlan` model (6 billing cycles including `custom`)
- ✅ `Subscription` model (no grace period, auto_renew=true default)
- ✅ `SubscriptionPayment` model
- ✅ `SubscriptionService` (create, renew, cancel, check access)
- ✅ `SubscriptionApiController` (6 endpoints)
- ✅ `CheckActiveSubscription` middleware (no grace period)
- ✅ 3-tier notification tracking (notified_7_days, notified_3_days, notified_1_day)
- ✅ Migrations (pending — ready to run)
- ✅ Routes registered in `api.php`

### Fixes Applied (2026-02-15)
- ❌→✅ Removed grace period from all code (Subscription model, Service, Middleware)
- ❌→✅ Changed `auto_renew` default from `false` to `true`
- ❌→✅ Added `custom` billing cycle (for admin-defined day count)
- ❌→✅ Replaced single `notification_days` with 3 boolean flags (7d, 3d, 1d)
- ❌→✅ Removed `grace_period_days` and `trial_videos_count` settings
- ❌→✅ Updated migration to match

### Remaining Phase 1 Tasks
- 🔲 Run migrations: `php artisan migrate`
- 🔲 Seed default subscription plans
- 🔲 Test all 6 API endpoints
- 🔲 Add `POST /api/subscription/renew` endpoint (missing from controller)

### API Endpoints (Phase 1)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/subscription/plans` | No | List active plans (paginated) |
| GET | `/api/subscription/my-subscription` | Yes | Current subscription status |
| POST | `/api/subscription/subscribe` | Yes | Subscribe to a plan |
| POST | `/api/subscription/renew` | Yes | Renew subscription |
| POST | `/api/subscription/cancel` | Yes | Cancel subscription |
| GET | `/api/subscription/history` | Yes | Payment history |
| POST | `/api/subscription/settings` | Yes | Toggle auto-renew |

#### GET `/api/subscription/plans` — Pagination

| Query Param | Type | Default | Description |
|-------------|------|---------|--------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Items per page (max 50) |

**Response:**
```json
{
  "status": true,
  "data": {
    "plans": [...],
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 25,
      "last_page": 2,
      "from": 1,
      "to": 15
    }
  }
}
```

#### Admin Subscription Plans List — Pagination

Admin index uses Bootstrap Table with **server-side pagination**:
- `offset`, `limit`, `sort`, `order`, `search` via AJAX
- Response: `{ total, rows }`
- Page sizes: 5, 10, 20, 50, 100

### Database: `subscription_plans`

| Field | Type | Description |
|-------|------|-------------|
| name | string | Plan name |
| slug | string | URL-friendly name (unique) |
| description | text | Plan description |
| duration_days | integer/null | Custom days (null for lifetime) |
| billing_cycle | enum | monthly/quarterly/semi_annual/yearly/lifetime/**custom** |
| price | decimal(10,2) | Base price (EGP) |
| features | json | Plan features list |
| is_active | boolean | Active for sale |
| sort_order | integer | Display order |

### Database: `subscriptions`

| Field | Type | Description |
|-------|------|-------------|
| user_id | foreignId | User reference |
| plan_id | foreignId | Plan reference |
| starts_at | timestamp | Start date |
| ends_at | timestamp/null | End date (null for lifetime) |
| status | enum | active/expired/cancelled/pending |
| auto_renew | boolean | Default: **true** |
| notified_7_days | boolean | 7-day notification sent |
| notified_3_days | boolean | 3-day notification sent |
| notified_1_day | boolean | 24-hour notification sent |
| cancellation_reason | string | Reason for cancellation |
| cancelled_at | timestamp | When cancelled |

---

## Phase 2: Content Access Control

> **Planning artifacts**: `phase2-spec.md`, `phase2-plan.md`, `phase2-tasks.md`, `phase2-contracts.md`

### 2.1 Video Progress Enforcement (85% Rule)

**Concept:** Student must watch 85% of actual video duration (not seeking) before the next lesson unlocks. If they leave and return, they resume from their last real progress.

**Anti-Cheating:** Hybrid approach - client tracks progress and periodically sends updates to server. Server randomly challenges client to prove current playback state (e.g., request screenshot hash, playback timestamp verification). This prevents bulk time manipulation while allowing normal playback behavior.

#### [NEW] Migration: `add_video_progress_tracking_fields`

Add to existing `user_curriculum_tracking` table or create new:

| Field | Type | Description |
|-------|------|-------------|
| user_id | foreignId | User reference |
| lecture_id | foreignId | Lecture reference |
| watched_seconds | integer | Actual seconds watched (server-validated) |
| total_seconds | integer | Total video duration |
| last_position | integer | Last playback position (for resume) |
| watch_percentage | decimal(5,2) | Calculated: watched_seconds/total_seconds * 100 |
| is_completed | boolean | True when watch_percentage >= 85 |
| completed_at | timestamp | When 85% threshold was reached |

#### [NEW] `VideoProgressService.php`

```php
updateProgress(User, Lecture, watchedSeconds, lastPosition, totalSeconds)
getProgress(User, Lecture): array
canAccessNextLesson(User, Lecture): bool  // checks if current lesson >= 85%
getCourseProgress(User, Course): float   // overall course completion %
validateProgressChallenge(User, Lecture, challengeResponse): bool  // validates random server challenge
generateProgressChallenge(User, Lecture): array  // generates challenge for client
```

#### [NEW] API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/lecture/{id}/progress` | Yes | Update watch progress (called periodically by player) |
| GET | `/api/lecture/{id}/progress` | Yes | Get current progress + last_position (for resume) |
| GET | `/api/course/{id}/progress` | Yes | Get full course progress breakdown |

#### [MODIFY] `CheckActiveSubscription` middleware or new middleware

Before serving a lecture, verify:
1. User is authenticated
2. User has active subscription OR lecture is marked free
3. Previous lesson is completed (>= 85%) OR this is the first lesson

### 2.2 Free Courses & Lessons

#### [MODIFY] Migration: `add_is_free_to_courses_and_lectures`

| Table | Field | Type | Description |
|-------|-------|------|-------------|
| courses | is_free | boolean | Entire course is free (default: false) |
| courses | is_free_until | timestamp/null | Free until this date (temporary free) |
| course_chapter_lectures | is_free | boolean | This specific lesson is free (default: false) |

#### [MODIFY] Access Logic

```
IF user is NOT logged in → REJECT (login required for everything)
IF course.is_free OR lecture.is_free → ALLOW
IF user has active subscription → ALLOW
ELSE → REJECT (subscription required)
```

### 2.3 File Attachments Under Videos

#### [NEW] Migration: `create_lecture_attachments_table`

| Field | Type | Description |
|-------|------|-------------|
| lecture_id | foreignId | Lecture reference |
| file_name | string | Original filename |
| file_path | string | Storage path |
| file_size | integer | Size in bytes |
| file_type | string | MIME type |
| sort_order | integer | Display order |

#### [NEW] Setting: `feature_lecture_attachments_enabled`

Admin toggle — initially set to `false` (hidden from users).

#### [NEW] API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/lecture/{id}/attachments` | Yes | List attachments (if feature enabled) |
| POST | `/api/admin/lecture/{id}/attachments` | Admin | Upload attachment |
| DELETE | `/api/admin/lecture/{id}/attachments/{attachmentId}` | Admin | Delete attachment |

### 2.4 Feature Flags System

#### [NEW] Migration: `create_feature_flags_table`

| Field | Type | Description |
|-------|------|-------------|
| key | string | Feature key (unique) |
| name | string | Display name |
| description | text | What this feature does |
| is_enabled | boolean | Currently active |
| metadata | json | Additional config |

#### Initial Feature Flags

| Key | Name | Default |
|-----|------|---------|
| `lecture_attachments` | ملفات مرفقة تحت الفيديو | false |
| `affiliate_system` | نظام التسويق بالعمولة | false |
| `video_progress_enforcement` | إلزام مشاهدة 85% | true |
| `comments_require_approval` | موافقة الأدمن على التعليقات | true |
| `ratings_require_approval` | موافقة الأدمن على التقييمات | true |

---

## Phase 3: Affiliate System

**Feature Status:** Initially **DISABLED** by default. Admin must explicitly enable from admin panel. When disabled, all affiliate UI, routes, and logic are inaccessible to users.

### Database

#### [NEW] `affiliate_links` table

| Field | Type | Description |
|-------|------|-------------|
| user_id | foreignId | Affiliate user |
| code | string | Unique referral code |
| total_clicks | integer | Click count |
| total_conversions | integer | Successful referrals |
| is_active | boolean | Link active |

#### [NEW] `affiliate_commissions` table

| Field | Type | Description |
|-------|------|-------------|
| affiliate_id | foreignId | Affiliate user |
| referred_user_id | foreignId | Referred user |
| subscription_id | foreignId | The subscription that triggered commission |
| plan_id | foreignId | The subscription plan (to track commission rate) |
| amount | decimal(10,2) | Commission amount (calculated using plan's commission_rate) |
| commission_rate | decimal(5,2) | Percentage used for this commission (snapshot from plan) |
| status | enum | pending/available/withdrawn/cancelled |
| earned_date | date | Date commission was earned |
| available_date | date | Date commission becomes available for withdrawal (calculated based on bi-monthly cycle) |
| settlement_period_start | date | Period start (1st or 16th) |
| settlement_period_end | date | Period end (15th or last day) |
| withdrawn_at | timestamp | When withdrawn |

#### [NEW] `affiliate_withdrawals` table

| Field | Type | Description |
|-------|------|-------------|
| affiliate_id | foreignId | Affiliate user |
| amount | decimal(10,2) | Withdrawal amount |
| commission_ids | json | Array of commission IDs included in this withdrawal |
| status | enum | pending/processing/completed/failed/rejected |
| requested_at | timestamp | When requested |
| processed_at | timestamp | When processed |
| processed_by | foreignId/null | Admin who processed |
| rejection_reason | text/null | If rejected |

#### [NEW] `affiliate_settings` table (or add to settings)

| Field | Type | Description |
|-------|------|-------------|
| min_withdrawal_amount | decimal(10,2) | Minimum amount to request withdrawal (default: 500 EGP) |
| is_enabled | boolean | Affiliate system enabled/disabled (default: false) |

#### [MODIFY] `subscription_plans` table - Add commission field

| Field | Type | Description |
|-------|------|-------------|
| commission_rate | decimal(5,2) | Commission percentage for this plan (e.g., 10.00 for 10%) |

### Business Rules

1. **Admin-controlled commission rates** — Each subscription plan has its own `commission_rate` percentage set by admin
2. **One-time commission only** — Commission calculated ONLY on the first subscription payment of referred user. If user renews, NO additional commission
3. **Bi-monthly calculation cycle:**
   - Commissions earned from day 1-15 of month → status changes from `pending` to `available` on day **28** of same month
   - Commissions earned from day 16-end of month → status changes from `pending` to `available` on day **15** of next month
   - While `pending`, commissions are locked and cannot be withdrawn
4. **Minimum withdrawal:** Admin configures global minimum (e.g., 500 EGP). Affiliates can only request withdrawal when their **available** balance ≥ minimum
5. **Feature toggle:** System disabled by default. When disabled:
   - All affiliate routes return 404 or redirect
   - Affiliate UI components hidden
   - No commission calculation occurs
   - Admin can enable/disable from admin panel without code changes

### Commission Calculation Example

```
User refers someone who subscribes to "Yearly Plan" (500 EGP)
Plan's commission_rate = 12%
Commission = 500 × 0.12 = 60 EGP

If earned on May 10:
- earned_date = 2026-05-10
- available_date = 2026-05-28 (because May 10 is between 1-15)
- status = 'pending' until May 28, then auto-changes to 'available'

If earned on May 20:
- earned_date = 2026-05-20
- available_date = 2026-06-15 (because May 20 is between 16-31)
- status = 'pending' until June 15, then auto-changes to 'available'
```

### [NEW] `AffiliateService.php`

```php
// Core affiliate operations
generateAffiliateLink(User): AffiliateLink
trackClick(code): void
processReferral(referredUser, subscription): ?AffiliateCommission
getCommissions(User, filters): Collection
getAvailableBalance(User): float  // Sum of 'available' commissions
getPendingBalance(User): float    // Sum of 'pending' commissions

// Withdrawal operations
requestWithdrawal(User, amount): AffiliateWithdrawal
getWithdrawals(User): Collection
processWithdrawal(AffiliateWithdrawal, adminUser): void
rejectWithdrawal(AffiliateWithdrawal, reason, adminUser): void

// Scheduled operations
releaseCommissions(): int  // Changes 'pending' to 'available' based on available_date
calculateCommissionAvailableDate(earnedDate): Carbon  // Implements bi-monthly logic

// Feature control
isAffiliateSystemEnabled(): bool
getMinimumWithdrawalAmount(): float
getAffiliateStats(User): array
```

### API Endpoints

**Note:** All endpoints return 404 or appropriate error when affiliate system is disabled.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/affiliate/status` | No | Check if affiliate system is enabled |
| GET | `/api/affiliate/my-link` | Yes | Get/generate affiliate link |
| GET | `/api/affiliate/stats` | Yes | Dashboard stats (available/pending balance, conversions) |
| GET | `/api/affiliate/commissions` | Yes | Commission history with status filter |
| GET | `/api/affiliate/withdrawals` | Yes | Withdrawal history |
| POST | `/api/affiliate/withdraw` | Yes | Request withdrawal (validates min amount + available balance) |
| GET | `/api/ref/{code}` | No | Track referral click |

#### Admin Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/admin/affiliate/settings` | Admin | Get affiliate settings |
| PUT | `/api/admin/affiliate/settings` | Admin | Update settings (enable/disable system, min withdrawal) |
| GET | `/api/admin/affiliate/withdrawals/pending` | Admin | List pending withdrawal requests |
| POST | `/api/admin/affiliate/withdrawals/{id}/approve` | Admin | Approve withdrawal |
| POST | `/api/admin/affiliate/withdrawals/{id}/reject` | Admin | Reject withdrawal with reason |
| GET | `/api/admin/affiliate/commissions` | Admin | All commissions with filters |
| GET | `/api/admin/affiliate/stats` | Admin | System-wide affiliate stats |

---

## Phase 4: Wallet & Pricing

### 4.1 Per-Country Pricing (Udemy-Style)

#### [NEW] `subscription_plan_prices` table

| Field | Type | Description |
|-------|------|-------------|
| plan_id | foreignId | Subscription plan |
| country_code | string(2) | ISO country code (EG, SA, AE, US...) |
| currency_code | string(3) | Currency (EGP, SAR, AED, USD...) |
| price | decimal(10,2) | Price in local currency |

#### [NEW] `supported_currencies` table

| Field | Type | Description |
|-------|------|-------------|
| country_code | string(2) | ISO country code |
| country_name | string | Country name |
| currency_code | string(3) | Currency code |
| currency_symbol | string | Symbol (ج.م, ﷼, $...) |
| exchange_rate_to_egp | decimal(10,4) | Exchange rate to EGP |
| is_active | boolean | Supported |

#### Business Rules

- Admin sets prices per plan per country from dashboard
- User sees prices in their country's currency (detected via GeoIP — `GeoLocationService` exists)
- **Internal billing is always in EGP** — conversion happens at display time
- User from country X **cannot see** country Y's pricing
- If no country-specific price exists, fallback to base price (EGP)

### 4.2 Wallet Integration & Payment Gateway

#### Payment Gateway: Kashier Integration

**Primary gateway:** Kashier (Egyptian payment gateway supporting EGP, credit cards, mobile wallets)

**Integration requirements:**
- [NEW] `KashierCheckoutService.php` - Handle Kashier API integration
- [MODIFY] `PaymentFactory.php` - Add Kashier as payment method option
- [NEW] Kashier webhook handler for payment callbacks
- Store Kashier credentials in settings (merchant_id, api_key, webhook_secret)

#### Wallet Integration

Already exists (`WalletService`). Needs:
- [MODIFY] `subscribe` endpoint to properly integrate wallet deduction
- [MODIFY] Payment flow: Check wallet balance first, then use Kashier for remaining amount
- [NEW] Wallet top-up via Kashier

---

## Phase 5: Admin, Roles & Reports

### 5.1 Supervisor Roles System (Replacing Instructors)

#### [MODIFY] Rename "Instructor" concept to "Supervisor" (مشرف)

**Display labels only** — keep DB column names for backward compatibility.

#### [NEW] Granular Permissions

| Permission Key | Arabic Name | Description |
|----------------|-------------|-------------|
| `manage_accounts` | إدارة الحسابات | View/manage user accounts |
| `manage_courses` | إدارة الكورسات | Edit/organize courses |
| `upload_courses` | رفع كورسات | Upload new courses |
| `manage_subscriptions` | إدارة الاشتراكات | View/manage subscriptions |
| `manage_finances` | إدارة المالية | View financial reports |
| `approve_comments` | الموافقة على التعليقات | Approve/reject comments |
| `approve_ratings` | الموافقة على التقييمات | Approve/reject ratings |
| `manage_affiliates` | إدارة المسوقين | Manage affiliate system |
| `manage_settings` | إدارة الإعدادات | System settings |
| `manage_plans` | إدارة الباقات | Subscription plan CRUD |
| `view_reports` | عرض التقارير | View reports dashboard |

#### [MODIFY] Use Spatie permissions (already installed)

```php
// Create permissions via seeder
Permission::create(['name' => 'manage_accounts']);
Permission::create(['name' => 'manage_courses']);
// ... etc

// Assign to supervisor
$supervisor->givePermissionTo('manage_courses', 'upload_courses');
```

### 5.2 Comment/Rating Approval System

#### [NEW] Migration: `add_approval_fields`

| Table | Field | Type | Default |
|-------|-------|------|---------|
| ratings | status | enum(pending/approved/rejected) | pending |
| ratings | reviewed_by | foreignId/null | null |
| ratings | reviewed_at | timestamp/null | null |
| course_discussions | status | enum(pending/approved/rejected) | pending |
| course_discussions | reviewed_by | foreignId/null | null |
| course_discussions | reviewed_at | timestamp/null | null |

#### [NEW] Admin API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/admin/reviews/pending` | Admin | List pending reviews |
| POST | `/api/admin/reviews/{id}/approve` | Admin | Approve review |
| POST | `/api/admin/reviews/{id}/reject` | Admin | Reject review |
| GET | `/api/admin/comments/pending` | Admin | List pending comments |
| POST | `/api/admin/comments/{id}/approve` | Admin | Approve comment |
| POST | `/api/admin/comments/{id}/reject` | Admin | Reject comment |

#### [MODIFY] Public API

- `GET /api/course/{id}/reviews` — Only return `status = approved`
- `GET /api/course/{id}/discussions` — Only return `status = approved`

### 5.3 Marketing Analytics Integration

**Injection Strategy:** Backend API-driven. Frontend calls `/api/marketing-pixels/active` during app initialization, receives list of active pixels with their IDs, then dynamically injects tracking scripts. This allows admin to enable/disable platforms instantly without frontend rebuild and works for SPA/mobile apps.

#### [NEW] Migration: `create_marketing_pixels_table`

| Field | Type | Description |
|-------|------|-------------|
| platform | enum | hotjar/microsoft_clarity/google_tag_manager/facebook/tiktok/snapchat/instagram |
| pixel_id | string | Platform-specific ID |
| is_active | boolean | Currently active |
| additional_config | json | Extra config (e.g., conversion events) |

#### [NEW] Admin API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/admin/marketing-pixels` | Admin | List all pixels |
| POST | `/api/admin/marketing-pixels` | Admin | Add/update pixel |
| DELETE | `/api/admin/marketing-pixels/{id}` | Admin | Remove pixel |

#### [NEW] Public API Endpoint

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/marketing-pixels/active` | No | Get active pixels for frontend injection (returns: platform, pixel_id, config) |

**Frontend Implementation:**
```javascript
// On app initialization
fetch('/api/marketing-pixels/active')
  .then(res => res.json())
  .then(pixels => {
    pixels.forEach(pixel => {
      injectPixel(pixel.platform, pixel.pixel_id, pixel.additional_config);
    });
  });
```

### 5.4 Subscription Plan Admin CRUD

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/admin/subscription-plans` | Admin | List all plans (including inactive) |
| POST | `/api/admin/subscription-plans` | Admin | Create plan |
| PUT | `/api/admin/subscription-plans/{id}` | Admin | Update plan |
| DELETE | `/api/admin/subscription-plans/{id}` | Admin | Soft delete plan |
| POST | `/api/admin/subscription-plans/{id}/toggle` | Admin | Activate/deactivate |
| PUT | `/api/admin/subscription-plans/{id}/sort` | Admin | Update sort order |
| POST | `/api/admin/subscription-plans/{id}/prices` | Admin | Set country-specific prices |

---

## Phase 6: Notifications & Certificates

### 6.1 Subscription Expiry Notifications

#### [NEW] `SendSubscriptionExpiryNotifications` Command

Runs daily via scheduler. Sends 3 types:

| Threshold | Push Title | Email Subject |
|-----------|-----------|---------------|
| 7 days | اشتراكك ينتهي خلال 7 أيام | تذكير: اشتراكك ينتهي قريباً |
| 3 days | اشتراكك ينتهي خلال 3 أيام | تنبيه: اشتراكك ينتهي خلال 3 أيام |
| 24 hours | اشتراكك ينتهي غداً! | عاجل: اشتراكك ينتهي خلال 24 ساعة |

Each notification is sent **once** (tracked by `notified_7_days`, `notified_3_days`, `notified_1_day` flags).

#### [NEW] `HandleExpiredSubscriptions` Command

Runs daily. Marks expired subscriptions and attempts auto-renewal.

#### [NEW] `ReleaseAffiliateCommissions` Command

Runs daily. Changes commission status from `pending` to `available` when `available_date <= today`.

#### [NEW] `ProcessAffiliateWithdrawals` Command

Optional: Auto-process approved withdrawals (or keep manual for better control).

### 6.2 Certificate QR Code

#### [MODIFY] `CertificateService.php`

- Add QR code generation using `simplesoftwareio/simple-qrcode` package
- QR contains verification URL: `{app_url}/certificate/verify/{certificate_number}`
- Embed QR in PDF template

#### [NEW] API Endpoint

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/certificate/verify/{number}` | No | Public verification page |

#### [MODIFY] Certificate Generation Logic

Before generating certificate:
1. Check `getCourseProgress(User, Course) === 100.0`
2. 100% means ALL lessons have `watch_percentage >= 85%`
3. If not 100% → reject with progress details

---

## Implementation Order (Recommended)

### Phase 1 (Current — finish first)
1. ✅ Fix grace period, auto-renew, custom cycle, notifications
2. 🔲 Run migrations
3. 🔲 Add `POST /api/subscription/renew` endpoint
4. 🔲 Seed default plans
5. 🔲 Test all endpoints

### Phase 2 (Next)
6. 🔲 Video progress tracking (85% rule)
7. 🔲 Free course/lesson flags
8. 🔲 Feature flags system
9. 🔲 Lecture attachments (behind feature flag)
10. 🔲 Lesson access middleware (sequential unlock)

### Phase 3
11. 🔲 Affiliate tables & models
12. 🔲 AffiliateService with bi-monthly release logic
13. 🔲 Affiliate API endpoints with feature toggle check
14. 🔲 Admin affiliate settings & withdrawal approval
15. 🔲 Add `commission_rate` to subscription plans
16. 🔲 Scheduled command: Release commissions (check available_date daily)

### Phase 4
15. 🔲 Country pricing tables
16. 🔲 GeoIP price detection
17. 🔲 Kashier payment gateway integration (KashierCheckoutService)
18. 🔲 Kashier webhook handling
19. 🔲 Wallet integration for subscriptions (wallet + Kashier split payment)
20. 🔲 Wallet top-up via Kashier

### Phase 5
21. 🔲 Supervisor permissions (Spatie)
22. 🔲 Comment/rating approval system
23. 🔲 Marketing pixels admin
24. 🔲 Subscription plan admin CRUD
25. 🔲 Country pricing admin

### Phase 6
26. 🔲 Expiry notification commands (7d, 3d, 24h)
27. 🔲 Auto-renewal command
28. 🔲 Affiliate commission release command (daily check of available_date)
29. 🔲 Certificate QR code
30. 🔲 100% completion gate for certificates
31. 🔲 Schedule all commands in Kernel

---

## Verification Plan

```bash
# Phase 1
php artisan migrate
php artisan test --filter=Subscription

# Phase 2
php artisan test --filter=VideoProgress
php artisan test --filter=ContentAccess

# Phase 3
php artisan test --filter=Affiliate

# Phase 5
php artisan test --filter=Permission
php artisan test --filter=Approval

# Phase 6
php artisan test --filter=Notification
php artisan test --filter=Certificate

# Full suite
php artisan test
```
