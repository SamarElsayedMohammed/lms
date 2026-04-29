# تصميم النظام الكامل — LMS Levora

**التاريخ:** 2026-04-29
**المصدر:** ملفات `specs/main/` الموجودة في المستودع فقط
**الحالة:** مسودة للمراجعة — لا تعديل كود قبل الموافقة

---

## 1) صورة النظام من الألف إلى الياء

```
المنصة (Levora LMS)
│
├── من يدخل النظام؟ ← Layer A: الحسابات والصلاحيات
│   ├── Super Admin
│   ├── Supervisor (Staff بصلاحيات جزئية)
│   └── Student (مستخدم مشترك)
│
├── ماذا يشترك؟ ← Layer B: الباقات والتسعير
│   ├── خطط اشتراك (6 أنواع دورة)
│   ├── تسعير أساسي بالجنيه المصري
│   └── عرض بعملة المستخدم (SA/AE/US/EG)
│
├── كيف يدفع؟ ← Layer C: الدفع والاشتراك
│   ├── Kashier (بوابة دفع)
│   ├── محفظة داخلية
│   └── تجديد تلقائي (auto_renew = true)
│
├── ماذا يشاهد؟ ← Layer D: المحتوى والوصول
│   ├── كورسات → فصول → محاضرات
│   ├── قاعدة 85% لإكمال الدرس
│   ├── دروس مجانية (is_free)
│   └── مرفقات المحاضرات (feature-flag)
│
├── كيف يُكافأ؟ ← Layer E: التسويق بالعمولة
│   ├── رابط إحالة فريد
│   ├── عمولة على أول اشتراك للمُحال
│   └── سحب بعد 500 جنيه فأكثر
│
└── كيف يُثبَّت إنجازه؟ ← Layer F: الشهادات والإشعارات
    ├── شهادة عند 100% إكمال الكورس
    ├── QR للتحقق العام
    └── إشعارات انتهاء الاشتراك (7d/3d/24h)
```

---

## Layer A — الحسابات والصلاحيات

### A1. أنواع الحسابات (من `spec.md` + `tasks.md` US4)

| النوع | الوصف | طريقة التسجيل |
|-------|--------|---------------|
| **Super Admin** | يملك كل الصلاحيات؛ لا يُقيَّد | يُنشأ عبر Seeder/CLI مباشرة |
| **Supervisor** (مشرف) | موظف بصلاحيات جزئية تُحدَّد بـ Spatie | يُنشئه الـ Admin من لوحة التحكم |
| **Student** (طالب) | يسجّل بنفسه، يشترك، يشاهد المحتوى | تسجيل ذاتي عبر API العامة |

> **قرار من الوثائق:** لا يوجد «Instructor منفصل» أو «Creator» في الملفات؛ مهمة رفع المحتوى تقع على Admin/Supervisor. إذا أُضيف لاحقاً يُكتب `spec` جديد تحت `specs/`.

---

### A2. الصلاحيات (من `tasks.md` US4 + `spec.md` Business Rules)

#### مجموعات الصلاحيات (Spatie Permission Groups)

| المجموعة | الصلاحيات | مَن يمنحها |
|----------|-----------|------------|
| **subscription-plans** | `subscription-plans-list`, `subscription-plans-create`, `subscription-plans-edit`, `subscription-plans-delete` | Admin للـ Supervisor |
| **manage_courses** | إدارة الكورسات والفصول والمحاضرات | Admin للـ Supervisor |
| **manage_accounts** | إدارة حسابات المستخدمين | Admin للـ Supervisor |
| **manage_finances** | المحفظة، السحوبات، الماليات | Admin للـ Supervisor |
| **manage_plans** | خطط الاشتراك (مكافئ لـ subscription-plans للأدمن) | Admin للـ Supervisor |
| **approve_ratings** | الموافقة/الرفض على التقييمات | Admin للـ Supervisor |
| **approve_comments** | الموافقة/الرفض على التعليقات | Admin للـ Supervisor |

#### قواعد تطبيق الصلاحيات (من `spec.md`)

```
Web  → ResponseService::noAnyPermissionThenRedirect()
API  → ResponseService::noPermissionThenSendJson()
Blade → @can('permission-name') / @canany(['p1','p2'])
```

---

### A3. نموذج بيانات الحساب (User)

من `data-model.md` (موجود مسبقاً في قاعدة البيانات):

```
users
├── id
├── name
├── email (unique)
├── password
├── phone (nullable)
├── referred_by → users.id (nullable)   ← للـ Affiliate
└── timestamps
```

**علاقات الصلاحيات عبر Spatie:**
```
users --hasMany--> model_has_roles
users --hasMany--> model_has_permissions
```

---

### A4. فجوات مكتشفة في Layer A

| الفجوة | الموصوف في الملفات | الوضع |
|--------|-------------------|-------|
| حقل `referred_by` على `users` | مذكور في `tasks.md` T091/T092 | يُضاف في مرحلة Affiliate |
| واجهة إنشاء/تعديل Supervisor | مذكورة في US4 | ضمن النطاق الحالي |
| تعديل اسم "Instructor" → "Supervisor" في UI | T021 في `tasks.md` | نصوص فقط — المسارات تبقى |

---

## Layer B — الباقات والتسعير

### B1. هيكل خطة الاشتراك (من `data-model.md`)

```
subscription_plans
├── id
├── name              string   (عربي: "شهري", "سنوي", إلخ)
├── billing_cycle     enum     monthly | quarterly | semi_annual | yearly | lifetime | custom
├── custom_days       int?     (فقط عند billing_cycle = custom)
├── price             decimal(10,2)   السعر الأساسي بالجنيه المصري
├── commission_rate   decimal(5,2)    نسبة عمولة الـ Affiliate
├── features          json     مصفوفة مميزات بالعربي
├── is_active         boolean  تفعيل/تعطيل الخطة
└── sort_order        int      ترتيب العرض
```

### B2. الخطط الافتراضية (من `spec.md`)

| الخطة | الاسم بالعربي | السعر (EGP) | نسبة العمولة |
|-------|---------------|-------------|--------------|
| monthly | شهري | 100 | 10% |
| quarterly | ربع سنوي | 270 | 12% |
| semi_annual | نصف سنوي | 500 | 15% |
| yearly | سنوي | 900 | 20% |
| lifetime | مدى الحياة | 2500 | 25% |

> تُبذَر عبر `SubscriptionPlanSeeder` بـ `firstOrCreate` (idempotent).

---

### B3. التسعير المحلي (من `data-model.md` + `contracts/admin-plans-api.md`)

#### جدول العملات المدعومة

```
supported_currencies
├── country_code         char(2) unique   (ISO 3166-1: EG, SA, AE, US)
├── country_name         string
├── currency_code        char(3)          (ISO 4217: EGP, SAR, AED, USD)
├── currency_symbol      string           (ج.م, ر.س, د.إ, $)
├── exchange_rate_to_egp decimal(10,4)    يجب أن يكون > 0
└── is_active            boolean
```

#### البذور الأولية (من `spec.md`)

| الدولة | العملة | الرمز | معدل الصرف (→ EGP) |
|--------|--------|-------|---------------------|
| EG | EGP | ج.م | 1.0000 |
| SA | SAR | ر.س | ~0.19 |
| AE | AED | د.إ | ~0.18 |
| US | USD | $ | ~0.03 |

#### أسعار لكل خطة لكل دولة

```
subscription_plan_prices
├── plan_id       → subscription_plans.id
├── country_code  char(2)
├── price         decimal(10,2)   السعر بالعملة المحلية
└── UNIQUE (plan_id, country_code)
```

---

### B4. كيف يظهر السعر في API (من `contracts/admin-plans-api.md`)

```json
GET /api/subscription/plans
{
  "data": [{
    "id": 1,
    "name": "شهري",
    "price": 100,
    "display_price": "15.00",
    "display_currency": "SAR",
    "display_symbol": "ر.س",
    "billing_cycle": "monthly",
    "features": ["ميزة 1", "ميزة 2"]
  }]
}
```

**المنطق:** `PricingService::detectUserCountry()` → جلب `SubscriptionPlanPrice` للدولة → fallback لـ EGP إذا لم يوجد سعر مخصص.

---

### B5. واجهات API لإدارة الباقات (Admin — من `contracts/admin-plans-api.md`)

| Endpoint | الغرض | الصلاحية |
|----------|--------|----------|
| `GET /api/admin/subscription-plans` | قائمة الخطط | subscription-plans-list |
| `POST /api/admin/subscription-plans` | إنشاء خطة | subscription-plans-create |
| `PUT /api/admin/subscription-plans/{id}` | تعديل | subscription-plans-edit |
| `DELETE /api/admin/subscription-plans/{id}` | حذف | subscription-plans-delete |
| `POST /api/admin/subscription-plans/{id}/toggle` | تفعيل/تعطيل | subscription-plans-edit |
| `PUT /api/admin/subscription-plans/sort` | ترتيب العرض | subscription-plans-edit |
| `POST /api/admin/subscription-plans/{id}/country-prices` | تسعير لكل دولة | subscription-plans-edit |
| `POST /api/subscription/renew` | تجديد اشتراك المستخدم | auth:sanctum |

---

## Layer C — الدفع والاشتراك

### C1. نموذج الاشتراك (من `plan_v2-summary.md`)

```
subscriptions
├── user_id           → users.id
├── plan_id           → subscription_plans.id
├── status            enum: active | expired | cancelled
├── starts_at         timestamp
├── ends_at           timestamp
├── auto_renew        boolean  (default: true)
├── notified_7_days   boolean  (false)
├── notified_3_days   boolean  (false)
└── notified_1_day    boolean  (false)
```

> **قرار مُثبَّت من الوثائق:** لا grace period، `auto_renew = true` افتراضي، تتبع الإشعارات بثلاثة flags منفصلة.

### C2. تدفق الدفع

```
User اختار خطة
    ↓
POST /api/subscription/subscribe (أو /pay عبر Kashier)
    ↓
KashierCheckoutService ← إنشاء جلسة دفع
    ↓
Webhook /kashier/webhook ← تأكيد الدفع
    ↓
SubscriptionService::create() ← تنشيط الاشتراك
    ↓
AffiliateService::processReferral() ← إذا كان المستخدم مُحالاً
```

---

## Layer D — المحتوى والوصول

### D1. قاعدة الوصول (من `contracts/content-access-api.md`)

```
للوصول لأي محتوى:
  1. المستخدم مسجّل (authenticated)
  2. إذا course.is_free OR lecture.is_free → مسموح مباشرة
  3. إذا لديه اشتراك نشط → مسموح
  4. للدرس التالي: الدرس السابق watch_percentage >= 85% أو هو أول درس
  5. وإلا → مرفوض
```

### D2. تتبع تقدم الفيديو (من `data-model.md` Phase 2)

```
lecture_progress (أو امتداد user_curriculum_tracking)
├── user_id
├── lecture_id
├── watched_seconds
├── total_seconds
├── last_position       (نقطة الاستئناف)
├── watch_percentage    decimal(5,2)
├── is_completed        true عند >= 85%
└── completed_at
```

### D3. واجهات API المحتوى

| Endpoint | الغرض |
|----------|--------|
| `POST /api/lecture/{id}/progress` | تحديث التقدم |
| `GET /api/lecture/{id}/progress` | جلب التقدم والاستئناف |
| `GET /api/course/{id}/progress` | تقدم الكورس كاملاً |
| `GET /api/lecture/{id}/attachments` | مرفقات (feature-flag) |

---

## Layer E — التسويق بالعمولة (Affiliate)

### E1. القواعد الأساسية (من `contracts/affiliate-api.md` + `data-model.md`)

- عمولة **لمرة واحدة** فقط على **أول اشتراك** للمستخدم المُحال
- نسبة العمولة = `subscription_plans.commission_rate` (snapshot وقت الاشتراك)
- تحرير العمولة: **bi-monthly** (1-15 → متاحة 28 نفس الشهر؛ 16-نهاية → متاحة 15 الشهر التالي)
- حد أدنى للسحب: **500 جنيه مصري**
- النظام قابل للتعطيل من الأدمن (feature flag `affiliate_system`)

### E2. جداول البيانات

```
affiliate_links        → user_id, code(unique), total_clicks, total_conversions, is_active
affiliate_commissions  → affiliate_id, referred_user_id, subscription_id, plan_id,
                         amount, commission_rate, status(pending/available/withdrawn/cancelled),
                         earned_date, available_date
affiliate_withdrawals  → affiliate_id, amount, commission_ids(json), status,
                         requested_at, processed_at, processed_by, rejection_reason
```

---

## Layer F — الشهادات والإشعارات

### F1. شهادة الإتمام (من `contracts/certificate-api.md`)

- **شرط الإصدار:** إكمال الكورس بنسبة 100% (جميع الدروس watch_percentage >= 85%)
- **QR Code:** يُدمج في PDF؛ URL: `{app_url}/certificate/verify/{certificate_number}`
- **التحقق العام:** `GET /certificate/verify/{number}` — بدون تسجيل دخول

### F2. إشعارات انتهاء الاشتراك (من `certificate-api.md`)

| الأمر | المهلة | Flag المُحدَّث |
|-------|--------|----------------|
| `subscriptions:send-expiry-notifications` | 7 أيام | notified_7_days |
| `subscriptions:send-expiry-notifications` | 3 أيام | notified_3_days |
| `subscriptions:send-expiry-notifications` | 24 ساعة | notified_1_day |
| `subscriptions:handle-expired` | يومياً | يُحوّل status → expired |
| `affiliate:release-commissions` | يومياً | pending → available |

كل الأوامر مجدولة يومياً في `app/Console/Kernel.php`.

---

## الموافقة على المحتوى (Content Approval)

### قواعد العمل (من `contracts/approval-api.md`)

- يُفعَّل/يُعطَّل عبر Feature Flag: `content_approval`
- عند التفعيل: التقييمات والتعليقات الجديدة → `status = pending`
- API العام يُرجع فقط السجلات `status = approved`
- الأدمن يرى قائمة pending ويوافق أو يرفض

---

## خريطة التبعيات الكاملة

```
Layer A (حسابات)
    ↓ يُعرِّف من يدير
Layer B (باقات + تسعير)
    ↓ يُعرِّف ماذا يشتري المستخدم
Layer C (دفع + اشتراك)
    ↓ يُعرِّف من لديه وصول
Layer D (محتوى + 85%)
    ↓ عند الإكمال 100%
Layer F (شهادات)

Layer C (اشتراك أول دفعة)
    ↓ يُفعِّل
Layer E (Affiliate عمولة)
    ↓ جدولة يومية
Layer F (إشعارات + تحرير عمولات)
```

---

## جدول القرارات (للمراجعة والتأكيد)

| # | القرار | القيمة من الملفات | حالة القرار |
|---|--------|-------------------|-------------|
| D1 | أنواع المستخدمين | Admin + Supervisor + Student | مُثبَّت في الوثائق |
| D2 | الباقات الافتراضية (5 خطط) | كما في جدول B2 أعلاه | مُثبَّت في `spec.md` |
| D3 | العملات الأولية | EG, SA, AE, US | مُثبَّت في `spec.md` |
| D4 | grace period | لا يوجد | مُثبَّت (إزالة مقصودة) |
| D5 | auto_renew default | true | مُثبَّت |
| D6 | نسبة 85% لإكمال الدرس | 85% watch_percentage | مُثبَّت |
| D7 | حد السحب في Affiliate | 500 EGP | مُثبَّت |
| D8 | تحرير العمولات | bi-monthly | مُثبَّت |
| D9 | إشعارات انتهاء | 7d + 3d + 24h | مُثبَّت |
| D10 | Instructor منفصل عن Supervisor | غير موجود في الوثائق | **يحتاج تأكيد** |
| D11 | نموذج الدفع الوحيد | Kashier + محفظة | مُثبَّت |
| D12 | شراء كورس بالقطعة (بدون اشتراك) | غير موجود في الوثائق | **يحتاج تأكيد** |

---

---

## ترتيب التنفيذ المقترح (مربوط بـ `specs/main/tasks.md`)

```
المرحلة 0 — Foundation (T001–T005)
    ├── php artisan migrate
    ├── SupportedCurrencySeeder   ← دول + عملات
    ├── RolePermissionSeeder      ← صلاحيات Spatie
    └── migrate:status للتحقق

المرحلة 1 — Layer A + B: حسابات وباقات (T006–T011)
    ├── Sidebar قسم "الاشتراكات" (@can)
    ├── subscription-plans/index (إنشاء + جدول + toggle)
    ├── subscription-plans/edit  (تعديل + أسعار دول)
    ├── subscription-plans/show  (إحصاءات + مشتركين)
    └── SubscriptionPlanSeeder (5 خطط)

المرحلة 2 — Layer B API (T012–T018)
    ├── POST /api/subscription/renew
    ├── toggle / sort / country-prices
    └── مسارات web + api

المرحلة 3 — Layer B تسعير محلي (T019–T020)
    ├── getPlans() → display_price/currency/symbol
    └── واجهة أسعار الدول في Edit

المرحلة 4 — Layer A كاملة (T021–T024) [US4 Supervisor]
    ├── إعادة تسمية Instructor → Supervisor في UI
    ├── واجهة permission checkboxes + syncPermissions
    └── permission checks في Controllers

المرحلة 5 — موافقة المحتوى (T025–T031) [US5]
    ├── feature flag: content_approval
    ├── RatingApiController + CourseDiscussionApiController
    ├── ApprovalController (6 methods)
    ├── مسارات web + api
    └── admin/approvals/index.blade.php

المرحلة 6 — Layer D: محتوى + 85% (T039–T047) [US6]
    ├── migration: lecture_progress
    ├── migration: is_free على courses + lectures
    ├── migration: lecture_attachments
    ├── VideoProgressService / ContentAccessService
    ├── LectureProgressApiController
    ├── LectureAttachmentController (admin)
    └── feature flags: lecture_attachments, video_progress_enforcement

المرحلة 7 — Layer E: Affiliate (T048–T056) [US7]
    ├── migrations: affiliate_links, commissions, withdrawals
    ├── Models: AffiliateLink, AffiliateCommission, AffiliateWithdrawal
    ├── AffiliateService
    ├── AffiliateApiController (public + admin)
    ├── GET /api/ref/{code} (click tracking)
    ├── ربط first-subscription → AffiliateService::processReferral
    └── Command: affiliate:release-commissions (daily)

المرحلة 8 — Layer F: إشعارات + شهادات (T057–T062) [US8]
    ├── Command: subscriptions:send-expiry-notifications (7d/3d/24h)
    ├── Mailable + Blade templates
    ├── Command: subscriptions:handle-expired
    ├── CertificateService: شرط 100% + QR embed
    ├── GET /certificate/verify/{number} (public)
    └── Kernel: جدولة يومية للأوامر الثلاثة

المرحلة 9 — تحقق تكاملي (T032–T038)
    ├── feature flags toggle
    ├── subscription → commission → withdrawal
    ├── pricing → Kashier → activation
    ├── progress 85% → certificate QR
    ├── notification commands
    ├── permission matrix
    └── linter: zero new errors
```

### تبعيات حرجة

| المرحلة | تتطلب اكتمال |
|---------|--------------|
| 1 (UI باقات) | 0 (Foundation) |
| 2 (API باقات) | 0 |
| 3 (تسعير محلي) | 0 + 2 |
| 4 (Supervisor) | مستقلة |
| 5 (Approval) | مستقلة |
| 6 (محتوى 85%) | 0 |
| 7 (Affiliate) | 0 + 2 (subscription flow) |
| 8 (شهادات/إشعارات) | 6 (للشرط 100%) |
| 9 (تحقق) | كل ما سبق |

**مراحل 2 + 4 + 5 + 6 تسير بالتوازي بعد اكتمال المرحلة 0.**

---

## ملاحظات الفجوات (Gaps)

| الفجوة | المصدر | الإجراء المقترح |
|--------|--------|----------------|
| حقل `referred_by` على جدول `users` | مذكور في `tasks.md` T091 لكن ليس في data-model.md بوضوح | يُضاف عند تنفيذ Affiliate |
| تفاصيل المحفظة (Wallet schema) | مذكور في plan_v2 Phase 4 لكن بدون جدول صريح في `data-model.md` | يحتاج `spec` خاص عند التنفيذ |
| واجهة إدارة المحتوى (رفع فيديو، تنظيم فصول) | في `specs/001-course-create-intro-structure/` (مؤجّل) | يُفعَّل عند ربط المحتوى بالاشتراك |
| تسعير بكسل التسويق (marketing pixels) | في tasks.md T074–T080 | ضمن Phase 5 عند التنفيذ |
