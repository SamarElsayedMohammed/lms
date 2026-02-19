# LMS Implementation Tasks — ClickUp Import Reference

**Project:** LMS Subscription & Feature System  
**Total Tasks:** 59 (4 Complete, 55 To Do)  
**Estimated Remaining Time:** ~50 hours  
**Format:** Bilingual (English / العربية) with execution time estimates

---

## How to Import

1. **CSV Import:** Use `clickup-import-tasks.csv` — ClickUp → List → ⋮ → Import → CSV
2. **Manual:** Copy task details below into ClickUp tasks
3. **Bulk:** Use ClickUp's bulk import or API with the structured data

## Status Convention

| Status    | Meaning                          | Tasks                    |
|-----------|----------------------------------|--------------------------|
| **Complete** | Task is done                    | T047, T048, T049, T050   |
| **To Do**    | Task is not complete / pending  | T051–T098, T008–T014     |

---

## Phase 7: Affiliate (Completed)

### T047 — Create Release Affiliate Commissions Command ✓
**Status:** Complete | **Time:** 45 min

**English:** Create artisan command `affiliate:release-commissions`. Calls `AffiliateService::releaseCommissions()`. Run daily via scheduler.

**العربية:** إنشاء أمر Artisan لإطلاق العمولات المعلقة. يُشغّل يومياً عبر المجدول.

---

## Phase 8: Per-Country Pricing

### T048 — Create Pricing Tables Migration ✓
**Status:** Complete | **Time:** 45 min

**English:** Migration for supported_currencies and subscription_plan_prices tables.

**العربية:** ترحيل جداول العملات المدعومة وأسعار الباقات.

---

### T049 — Create Pricing Models ✓
**Status:** Complete | **Time:** 60 min

**English:** SupportedCurrency, SubscriptionPlanPrice models. countryPrices() on SubscriptionPlan.

**العربية:** نماذج التسعير مع العلاقات.

---

### T050 — Create PricingService ✓
**Status:** Complete | **Time:** 90 min

**English:** PricingService with getPriceForCountry(), detectUserCountry(), convertToEgp().

**العربية:** خدمة التسعير مع كشف الدولة وتحويل العملات.

---

### T051 — Modify Subscription Plans API for Localized Prices
**Status:** To Do | **Time:** 60 min

**English:**
In `app/Http/Controllers/API/SubscriptionApiController.php` method `getPlans()`: detect user country via PricingService, return each plan with display_price, display_currency, display_symbol from PricingService::getPriceForCountry().

**العربية:**
تعديل واجهة الباقات لإرجاع السعر بعملة المستخدم المحلية (display_price, display_currency, display_symbol).

**Acceptance:** API returns localized prices per user country.

---

### T052 — Create SupportedCurrencySeeder
**Time:** 45 min

**English:**
Create `database/seeders/SupportedCurrencySeeder.php`. Seed: EG (EGP, ج.م), SA (SAR, ﷼), AE (AED, د.إ), US (USD, $), plus additional countries. Approximate exchange rates; admin can update later. Register in DatabaseSeeder.

**العربية:**
إنشاء بذار للعملات: مصر، السعودية، الإمارات، أمريكا مع أسعار صرف تقريبية.

**Acceptance:** Currencies seeded.

---

### T053 — Add Admin UI for Country Pricing on Plan Edit
**Time:** 120 min

**English:**
On `resources/views/admin/subscription-plans/edit.blade.php` add section "Country Prices" / "أسعار الدول". Table: country name, currency, price input per SupportedCurrency. Save via AJAX or form submit to new route. Controller method to upsert SubscriptionPlanPrice records.

**العربية:**
إضافة قسم أسعار الدول في صفحة تعديل الباقة. جدول بالدول وحقول الأسعار. الحفظ عبر AJAX.

**Acceptance:** Admin sets per-country prices; values persist.

---

### T054 — Run Pricing Migrations and Seeder
**Time:** 15 min

**English:**
Execute `php artisan migrate` and `php artisan db:seed --class=SupportedCurrencySeeder` (or equivalent). Verify tables and seed data exist.

**العربية:**
تشغيل ترحيلات وبذار جداول التسعير.

**Acceptance:** Tables + seed data exist.

---

## Phase 9: Kashier Payment Gateway

### T055 — Create KashierCheckoutService
**Time:** 150 min

**English:**
Create `app/Services/Payment/KashierCheckoutService.php` implementing PaymentGatewayContract. Methods: `createCheckoutSession(SubscriptionPlan $plan, User $user, float $amount): array` — call Kashier API, return redirect URL/iframe. `verifyPayment(array $data): bool` — verify webhook signature. `getPaymentStatus(string $transactionId): string`. Settings: kashier_merchant_id, kashier_api_key, kashier_webhook_secret, kashier_mode (test/live).

**العربية:**
إنشاء خدمة دفع Kashier. إنشاء جلسة الدفع، التحقق من الويب هوك، الاستعلام عن حالة الدفع.

**Acceptance:** Checkout session created; webhook verified.

---

### T056 — Register Kashier in PaymentFactory
**Time:** 30 min

**English:**
In `app/Services/Payment/PaymentFactory.php` add `case 'kashier': return new KashierCheckoutService();` (or resolve from container).

**العربية:**
إضافة Kashier في PaymentFactory.

**Acceptance:** PaymentFactory::create('kashier') returns KashierCheckoutService.

---

### T057 — Create Kashier Webhook Handler
**Time:** 120 min

**English:**
Create `app/Http/Controllers/KashierController.php`. Method `handleWebhook(Request $request)`: verify signature, process payment status. On success: activate subscription via SubscriptionService, create SubscriptionPayment record. Route: POST /webhooks/kashier. Exclude from CSRF in VerifyCsrfToken middleware.

**العربية:**
إنشاء معالج ويب هوك Kashier. التحقق من التوقيع، تفعيل الاشتراك، إنشاء سجل الدفع.

**Acceptance:** Webhook processes payment; subscription activated.

---

### T058 — Add Kashier Settings to Admin
**Time:** 60 min

**English:**
In SettingsController and settings view (Payment section): add fields kashier_merchant_id, kashier_api_key, kashier_webhook_secret, kashier_mode (dropdown: test/live). Store in settings table. Use existing settings pattern.

**العربية:**
إضافة حقول إعدادات Kashier في لوحة التحكم (قسم الدفع).

**Acceptance:** Admin configures Kashier credentials.

---

### T059 — Modify Subscription Flow for Kashier
**Time:** 120 min

**English:**
In SubscriptionService::subscribe(): 1) Check wallet balance. 2) Deduct from wallet if sufficient. 3) If remaining amount > 0, create Kashier checkout for remainder. Add method `walletAndGatewayPayment(User $user, SubscriptionPlan $plan, float $totalAmount): array` to handle split payment logic.

**العربية:**
دمج المحفظة مع Kashier. خصم من المحفظة أولاً، ثم إنشاء جلسة Kashier للمتبقي.

**Acceptance:** Full wallet, partial wallet, or full Kashier all work.

---

### T060 — Register Kashier Webhook Route
**Time:** 15 min

**English:**
In routes/web.php add `POST /webhooks/kashier` → KashierController@handleWebhook. Add to $except in VerifyCsrfToken middleware.

**العربية:**
تسجيل مسار ويب هوك واستثناؤه من CSRF.

**Acceptance:** Route accessible; CSRF bypassed.

---

## Phase 10: Supervisor Roles & Permissions

### T061 — Update Permission Seeder
**Time:** 60 min

**English:**
In `database/seeders/RolePermissionSeeder.php` add permissions: manage_accounts, manage_courses, upload_courses, manage_subscriptions, manage_finances, approve_comments, approve_ratings, manage_affiliates, manage_settings, manage_plans, view_reports. Create role "Supervisor" (مشرف). Assign permissions. Keep existing roles intact.

**العربية:**
إضافة صلاحيات جديدة ودور المشرف في بذار الصلاحيات.

**Acceptance:** Permissions and Supervisor role exist.

---

### T062 — Rename Instructor to Supervisor in UI
**Time:** 45 min

**English:**
Update display labels only (NOT database/route names): sidebar.blade.php "Instructors" → "Supervisors" / "المشرفين"; instructor views; InstructorController page titles. Use __() for Arabic.

**العربية:**
تحديث النصوص في الواجهة من المدرب إلى المشرف. الحفاظ على أسماء قاعدة البيانات والمسارات.

**Acceptance:** UI shows "Supervisor" everywhere.

---

### T063 — Create Admin UI for Role Permission Assignment
**Time:** 90 min

**English:**
Update staff/user edit view (resources/views/Roles/ or admin/users/). Add permission checkboxes when editing supervisor. Save via Spatie `assignRole()` and `givePermissionTo()`. Update StaffController or RoleController (app/Http/Controllers/RoleController.php).

**العربية:**
إضافة واجهة تعيين الصلاحيات عند تعديل المشرف. صناديق اختيار للصلاحيات.

**Acceptance:** Admin assigns granular permissions to supervisors.

---

### T064 — Add Permission Checks to Admin Controllers
**Time:** 60 min

**English:**
Add middleware or @can: CoursesController → manage_courses; UserController → manage_accounts; WalletController → manage_finances; RatingController → approve_ratings; SubscriptionPlanController → manage_plans; SettingsController → manage_settings. Use `$this->middleware('permission:xxx')` or @can in views.

**العربية:**
إضافة فحص الصلاحيات في متحكمات الأدمن. 403 لمن لا يملك الصلاحية.

**Acceptance:** Unauthorized supervisor gets 403.

---

### T065 — Run Permissions Seeder
**Time:** 15 min

**English:**
Execute `php artisan db:seed --class=RolePermissionSeeder`.

**العربية:**
تشغيل بذار الصلاحيات.

**Acceptance:** New permissions and Supervisor role in DB.

---

## Phase 11: Comment/Rating Approval System

### T066 — Create Approval Fields Migration
**Time:** 45 min

**English:**
Migration `database/migrations/2026_02_16_300001_add_approval_fields_to_ratings_and_discussions.php`. ratings: add status (enum pending/approved/rejected, default pending), reviewed_by (foreignId nullable), reviewed_at (timestamp nullable). course_discussions: same columns.

**العربية:**
إضافة حقول الموافقة (status, reviewed_by, reviewed_at) لجدولي التقييمات والمناقشات.

**Acceptance:** Columns added.

---

### T067 — Update Rating Model
**Time:** 30 min

**English:**
Add status, reviewed_by, reviewed_at to fillable. Casts: reviewed_at => datetime. Scopes: scopeApproved($q), scopePending($q).

**العربية:**
تحديث نموذج Rating بحقول الموافقة والنطاقات.

**Acceptance:** Rating::approved()->get() works.

---

### T068 — Update CourseDiscussion Model
**Time:** 30 min

**English:**
Same as Rating: fillable, casts, scopeApproved, scopePending.

**العربية:**
تحديث نموذج CourseDiscussion بنفس التعديلات.

**Acceptance:** CourseDiscussion::approved()->get() works.

---

### T069 — Modify Public API to Filter by Approval
**Time:** 60 min

**English:**
In RatingApiController and CourseDiscussionApiController listing endpoints: when FeatureFlagService::isEnabled('ratings_require_approval') or 'comments_require_approval', add ->approved() to query. New submissions default status = 'pending'.

**العربية:**
تصفية التقييمات والمناقشات حسب الموافقة عند تفعيل الميزة.

**Acceptance:** Only approved visible when flag on.

---

### T070 — Create Admin ApprovalController
**Time:** 90 min

**English:**
Create `app/Http/Controllers/Admin/ApprovalController.php`. Methods: pendingRatings(), approveRating($id), rejectRating($id), pendingComments(), approveComment($id), rejectComment($id). Set reviewed_by, reviewed_at on approve.

**العربية:**
إنشاء متحكم الموافقة. قائمة المعلقة، الموافقة، الرفض.

**Acceptance:** Admin approves/rejects; status persists.

---

### T071 — Register Approval Admin Routes
**Time:** 30 min

**English:**
Routes: GET /api/admin/reviews/pending, POST /api/admin/reviews/{id}/approve, POST /api/admin/reviews/{id}/reject. Same for comments. Middleware: permission approve_comments, approve_ratings.

**العربية:**
تسجيل مسارات API للموافقة.

**Acceptance:** Routes resolve with permissions.

---

### T072 — Create Admin Approval UI
**Time:** 90 min

**English:**
Create `resources/views/admin/approvals/index.blade.php`. Tabs: Ratings, Comments. Each tab: table of pending items with Approve/Reject buttons. Sidebar link under "Content Management" / "إدارة المحتوى".

**العربية:**
إنشاء صفحة الموافقة مع تبويبات للتقييمات والتعليقات.

**Acceptance:** Admin sees pending; can approve/reject via UI.

---

### T073 — Run Approval Migration
**Time:** 15 min

**English:**
Execute `php artisan migrate`.

**العربية:**
تشغيل ترحيل الموافقة.

**Acceptance:** Columns added; no errors.

---

## Phase 12: Marketing Pixels

### T074 — Create Marketing Pixels Table Migration
**Time:** 30 min

**English:**
Table marketing_pixels: id, platform (enum: hotjar, microsoft_clarity, google_tag_manager, facebook, tiktok, snapchat, instagram), pixel_id (string), is_active (boolean default false), additional_config (json nullable), timestamps. Unique on platform.

**العربية:**
إنشاء جدول بكسل التسويق لمنصات التحليلات.

**Acceptance:** Table created.

---

### T075 — Create MarketingPixel Model
**Time:** 30 min

**English:**
Fillable: platform, pixel_id, is_active, additional_config. Casts: is_active => boolean, additional_config => array. Scope: scopeActive().

**العربية:**
إنشاء نموذج MarketingPixel.

**Acceptance:** Model works.

---

### T076 — Create Admin MarketingPixelController
**Time:** 60 min

**English:**
index(), store() — validate platform, pixel_id; updateOrCreate by platform. destroy($id). Apply admin middleware.

**العربية:**
إنشاء متحكم إدارة البكسل.

**Acceptance:** Admin CRUD for pixels.

---

### T077 — Create Public API for Active Pixels
**Time:** 45 min

**English:**
Create MarketingPixelApiController or add to existing. getActivePixels(): return MarketingPixel::active()->get(). Cache 60 seconds. No auth. Route: GET /api/marketing-pixels/active.

**العربية:**
إنشاء API عام للبكسل النشط مع تخزين مؤقت 60 ثانية.

**Acceptance:** API returns active pixels; cached.

---

### T078 — Register Marketing Pixel Routes
**Time:** 30 min

**English:**
Admin: GET /marketing-pixels, POST /marketing-pixels, DELETE /marketing-pixels/{id}. Public: GET /api/marketing-pixels/active.

**العربية:**
تسجيل مسارات إدارة وعرض البكسل.

**Acceptance:** Routes resolve.

---

### T079 — Create Admin Marketing Pixels View
**Time:** 90 min

**English:**
Create `resources/views/admin/marketing-pixels/index.blade.php`. Table: Platform, Pixel ID, Status toggle, Delete. Form: platform dropdown, pixel_id input. Sidebar link under Settings.

**العربية:**
إنشاء صفحة إدارة البكسل في لوحة التحكم.

**Acceptance:** Admin manages pixels from dashboard.

---

### T080 — Run Marketing Pixels Migration
**Time:** 15 min

**English:**
Execute `php artisan migrate`.

**العربية:**
تشغيل ترحيل جدول البكسل.

**Acceptance:** Table created.

---

## Phase 13: Subscription Plan Admin CRUD API

### T081 — Extend SubscriptionPlanController for Full CRUD API
**Time:** 90 min

**English:**
Verify index, create, store, edit, update, destroy. Add: toggle($id) — flip is_active. updateSortOrder($request, $id). setCountryPrices($request, $id) — upsert SubscriptionPlanPrice. All return JSON for API.

**العربية:**
إضافة طرق التبديل والترتيب وأسعار الدول لمتحكم الباقات.

**Acceptance:** Full CRUD + toggle + sort + country prices.

---

### T082 — Register Admin Plan Management Routes
**Time:** 30 min

**English:**
POST /admin/subscription-plans/{id}/toggle, PUT /admin/subscription-plans/{id}/sort, POST /admin/subscription-plans/{id}/prices. API: POST /api/admin/subscription-plans/{id}/toggle, etc.

**العربية:**
تسجيل مسارات API لإدارة الباقات.

**Acceptance:** Routes resolve.

---

## Phase 14: Expiry Notifications

### T083 — Create SendSubscriptionExpiryNotifications Command
**Time:** 90 min

**English:**
Command: subscriptions:send-expiry-notifications. Query active subscriptions with ends_at NOT NULL. For 7d, 3d, 1d: find where ends_at within threshold AND notified_X_days false. Send push (FCM) + email. Set notified_X_days = true. Arabic titles: "اشتراكك ينتهي خلال 7 أيام", etc. Skip lifetime.

**العربية:**
إنشاء أمر إرسال إشعارات انتهاء الاشتراك (7 أيام، 3 أيام، 24 ساعة). إشعار دفع + بريد.

**Acceptance:** Command sends notifications; flags prevent duplicates.

---

### T084 — Create Expiry Notification Email Templates
**Time:** 60 min

**English:**
Blade: subscription-expiry-7days.blade.php, subscription-expiry-3days.blade.php, subscription-expiry-24hours.blade.php. Each: user name, plan name, expiry date, renewal link. Use existing email layout.

**العربية:**
إنشاء قوالب البريد الإلكتروني للإشعارات الثلاثة.

**Acceptance:** Emails render with dynamic data.

---

### T085 — Create Laravel Mailable Classes
**Time:** 60 min

**English:**
SubscriptionExpiry7Days, SubscriptionExpiry3Days, SubscriptionExpiry24Hours. Each accepts User + Subscription. Render corresponding Blade template.

**العربية:**
إنشاء فئات Mailable لرسائل انتهاء الاشتراك.

**Acceptance:** Mailables work.

---

## Phase 15: Auto-Renewal & Expiry

### T086 — Create HandleExpiredSubscriptions Command
**Time:** 90 min

**English:**
Command: subscriptions:handle-expired. Find active where ends_at < now() AND ends_at NOT NULL. For each: if auto_renew, attempt SubscriptionService::renewWithPayment() (wallet then Kashier). Else set status = 'expired'. Log results.

**العربية:**
إنشاء أمر معالجة الاشتراكات المنتهية. التجديد التلقائي أو إنهاء الاشتراك.

**Acceptance:** Expired + auto_renew → renewed; else → expired.

---

## Phase 16: Certificate QR & 100% Gate

### T087 — Install QR Code Package
**Time:** 15 min

**English:**
`composer require simplesoftwareio/simple-qrcode`

**العربية:**
تثبيت حزمة إنشاء رمز QR.

**Acceptance:** Package installed.

---

### T088 — Modify CertificateService for 100% Gate and QR
**Time:** 90 min

**English:**
Before generating: check VideoProgressService::getCourseProgress($user, $course) === 100.0. If not, throw/return error. Add QR: URL = {app.url}/certificate/verify/{number}. QrCode::format('png')->size(150)->generate($url). Embed in PDF template.

**العربية:**
إضافة شرط إكمال 100% للشهادة. إنشاء رمز QR ودمجه في PDF.

**Acceptance:** Certificate rejected if < 100%; QR visible on certificate.

---

### T089 — Create Certificate Verification Endpoint
**Time:** 60 min

**English:**
Route GET /certificate/verify/{number} (public, no auth). Controller: lookup certificate, return view with course name, student name, completion date. View: resources/views/certificates/verify.blade.php.

**العربية:**
إنشاء صفحة التحقق من صحة الشهادة عبر مسح QR.

**Acceptance:** URL shows verification page.

---

## Phase 17: Polish & Integration

### T090 — Register Commands in Scheduler
**Time:** 30 min

**English:**
In routes/console.php or Kernel: schedule subscriptions:send-expiry-notifications daily; subscriptions:handle-expired daily; affiliate:release-commissions daily.

**العربية:**
تسجيل أوامر الاشتراك والعمولات في المجدول اليومي.

**Acceptance:** php artisan schedule:list shows all 3.

---

### T091 — Add referred_by Field to Users Table
**Time:** 30 min

**English:**
Migration: add referred_by (foreignId nullable → users). User model: fillable, referrer() belongsTo User.

**العربية:**
إضافة عمود المُحيل لربط المستخدم بالمسوق بالعمولة.

**Acceptance:** Column exists; relation works.

---

### T092 — Add User Registration Hook for Affiliate Tracking
**Time:** 60 min

**English:**
In registration (AuthController or API auth): if referral cookie/session exists, set referred_by = affiliate user ID on new user. Persist beyond session.

**العربية:**
حفظ المُحيل عند تسجيل مستخدم جديد برابط العمولة.

**Acceptance:** referred_by populated when user registers with referral.

---

### T093 — Backfill Existing Ratings and Comments
**Time:** 30 min

**English:**
Migration or command: UPDATE ratings SET status = 'approved' WHERE status IS NULL. Same for course_discussions. Ensures existing content visible after approval system.

**العربية:**
تعيين الموافقة للتقييمات والتعليقات الموجودة مسبقاً.

**Acceptance:** All existing have status = approved.

---

### T094 — Add commission_rate to Subscription Plan Admin Forms
**Time:** 45 min

**English:**
In create.blade.php and edit.blade.php add input "Commission Rate (%)" / "نسبة العمولة (%)". Numeric validation. Save in SubscriptionPlanController store/update.

**العربية:**
إضافة حقل نسبة العمولة في نماذج إنشاء وتعديل الباقة.

**Acceptance:** Admin sets commission rate per plan.

---

### T095 — Wallet Top-up via Kashier
**Time:** 120 min

**English:**
Add POST /api/wallet/top-up. User provides amount. Create Kashier checkout for wallet deposit. On webhook: credit wallet via WalletService. Route in api.php, auth:sanctum.

**العربية:**
إضافة إمكانية شحن المحفظة عبر Kashier.

**Acceptance:** User tops up wallet; webhook credits balance.

---

### T096 — Admin Currencies Management UI
**Time:** 120 min

**English:**
Create admin/currencies/index.blade.php. List SupportedCurrency. Allow update exchange rates, add/remove. CurrencyController. Sidebar link. Permission: manage_settings.

**العربية:**
إنشاء صفحة إدارة العملات المدعومة في لوحة التحكم.

**Acceptance:** Admin manages currencies.

---

### T097 — Add Marketing Pixels Sidebar Link
**Time:** 15 min

**English:**
In sidebar.blade.php add link "Marketing Pixels" / "بكسل التسويق" under Settings. @can('manage_settings').

**العربية:**
إضافة رابط إدارة البكسل في القائمة الجانبية.

**Acceptance:** Link visible to admin.

---

### T098 — Final Integration Verification
**Time:** 120 min

**English:**
Verify end-to-end: feature flags; subscription → commission → withdrawal; per-country pricing → Kashier → activation; 85% progress → next lesson → certificate; notification commands. Document any issues.

**العربية:**
التحقق الشامل من تكامل جميع الميزات.

**Acceptance:** Full system integration test passes.

---

## Phase 1: Subscription Plans Admin Views

### T008 — Create Plans Index View
**Time:** 120 min

**English:**
Create `resources/views/admin/subscription-plans/index.blade.php`. Top: inline create form (name, cycle, custom days, price, commission rate, features, sort order). Bottom: table (name, price, cycle, subscribers count, commission %, status toggle, actions). AJAX create/edit/delete. Follow promo-codes pattern.

**العربية:**
إنشاء صفحة قائمة الباقات مع نموذج إنشاء وجدول بيانات. إنشاء وتعديل وحذف عبر AJAX.

**Acceptance:** Admin creates/edits/deletes plans; toggle works.

---

### T009 — Create Plan Edit Modal/Form
**Time:** 90 min

**English:**
Create `resources/views/admin/subscription-plans/edit.blade.php`. Pre-filled form. Dynamic features list (add/remove). Show/hide custom days based on billing_cycle selection.

**العربية:**
إنشاء نموذج تعديل الباقة مع قائمة الميزات الديناميكية.

**Acceptance:** Edit form works; custom days conditional.

---

### T010 — Create Plan Show/Details View
**Time:** 90 min

**English:**
Create `resources/views/admin/subscription-plans/show.blade.php`. Plan summary card. Stats: total subscribers, active, revenue estimate. Subscribers table: name, email, start, end, status, auto_renew. Pagination.

**العربية:**
إنشاء صفحة تفاصيل الباقة مع إحصائيات وقائمة المشتركين.

**Acceptance:** Plan details and subscribers displayed.

---

### T011 — Add Subscriptions Section to Sidebar
**Time:** 30 min

**English:**
In sidebar.blade.php: new header "الاشتراكات" / "Subscriptions". Menu item "باقات الاشتراك" / "Subscription Plans" → subscription-plans.index. Icon fas fa-gem. @can('subscription-plans-list'). Active state: $type_menu === 'subscription-plans'.

**العربية:**
إضافة قسم الاشتراكات ورابط الباقات في القائمة الجانبية.

**Acceptance:** Sidebar shows link; permission-gated.

---

### T012 — Create SubscriptionPlanSeeder
**Time:** 60 min

**English:**
Create `database/seeders/SubscriptionPlanSeeder.php`. 5 plans: Monthly (شهري, 100 EGP, 30 days, 10%), Quarterly (ربع سنوي, 270, 90, 12%), Semi-Annual (نصف سنوي, 500, 180, 15%), Yearly (سنوي, 900, 365, 20%), Lifetime (مدى الحياة, 2500, null, 25%). Each with features JSON. Use updateOrCreate for idempotency.

**العربية:**
إنشاء بذار لخمس باقات افتراضية بالعربية.

**Acceptance:** 5 plans seeded.

---

### T013 — Run SubscriptionPlanSeeder
**Time:** 15 min

**English:**
Execute `php artisan db:seed --class=SubscriptionPlanSeeder`.

**العربية:**
تشغيل بذار الباقات الافتراضية.

**Acceptance:** Plans in DB.

---

### T014 — Add Renew Method to SubscriptionApiController
**Time:** 60 min

**English:**
Add renew() method. Validate request. Call SubscriptionService::renew() or equivalent. Register POST /api/subscription/renew in routes/api.php. Auth required.

**العربية:**
إضافة نقطة نهاية تجديد الاشتراك في API.

**Acceptance:** POST /api/subscription/renew works.

---

## Summary Table

| Phase | Tasks | Total Time |
|-------|-------|------------|
| Phase 8 | T051-T054 | 4.5 h |
| Phase 9 | T055-T060 | 8 h |
| Phase 10 | T061-T065 | 4.5 h |
| Phase 11 | T066-T073 | 6 h |
| Phase 12 | T074-T080 | 5 h |
| Phase 13 | T081-T082 | 2 h |
| Phase 14 | T083-T085 | 3.5 h |
| Phase 15 | T086 | 1.5 h |
| Phase 16 | T087-T089 | 2.75 h |
| Phase 17 | T090-T098 | 9.5 h |
| Phase 1 (Views) | T008-T014 | 8 h |
| **Total** | **59 tasks** (4 Complete, 55 To Do) | **~50 h remaining** |
