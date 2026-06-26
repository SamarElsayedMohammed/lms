# تقرير مشاكل نظام Authentication

تاريخ الاختبار: 25 يونيو 2026  
السيرفر المختبر:

`https://toogowgo4ckks8cok84o4c4w.187.77.77.216.sslip.io`

## ملخص الحالة

تم اختبار التسجيل وتسجيل الدخول بالإيميل والموبايل، Social Login، Firebase، استرجاع كلمة المرور، Access/Refresh Tokens، تغيير كلمة المرور، والجلسات.

النتيجة:

- توجد ثغرة حرجة تسمح بتجاوز Firebase بالكامل.
- تسجيل الموبايل لا يفرض إثبات OTP.
- توجد مشاكل في كود الدولة وحد الأجهزة وتكرار التسجيل.
- Firebase غير مجهز على السيرفر.
- دورة Access/Refresh Tokens وتغيير كلمة المرور تعمل بصورة جيدة.

---

## BUG-001 — تجاوز Firebase باستخدام توكن اختبار ثابت

**الخطورة:** حرجة — Critical  
**الحالة:** مؤكدة على السيرفر  
**المكان:** `ApiService::verifyFirebaseToken()`  
**القيمة الموجودة في الكود:** `skilso-no-firebase`

### المشكلة

الباك إند يقبل قيمة ثابتة بدل Firebase ID Token حقيقي، ثم ينشئ Firebase UID وهميًا اعتمادًا على الإيميل أو رقم الموبايل.

### السيناريوهات التي نجحت بهذه القيمة

- إنشاء حساب Google غير موثق.
- تسجيل الدخول إلى حساب Google الوهمي.
- إنشاء حساب موبايل وربطه بهوية Firebase وهمية.
- تغيير كلمة مرور حساب الموبايل دون OTP حقيقي.

### النتيجة الحالية

الطلبات ترجع `HTTP 200` ويتم إنشاء الحساب أو تغيير كلمة المرور.

### النتيجة المتوقعة

أي قيمة ليست Firebase ID Token موقعًا وصالحًا يجب أن ترجع `401` أو `422`.

### التأثير

يمكن تجاوز التحقق من هوية Google أو Apple أو رقم الهاتف بالكامل.

### الإصلاح المطلوب

- حذف شرط `skilso-no-firebase` نهائيًا من كود Production.
- إذا كانت القيمة مطلوبة للاختبارات، تُنقل إلى Fake/Mock داخل بيئة الاختبار فقط.
- رفض تشغيل أي Firebase bypass عندما لا تكون البيئة `testing`.
- إلغاء أو مراجعة الحسابات التي أُنشئت بهذه الهوية الوهمية.

---

## BUG-002 — التسجيل بالموبايل ينجح دون OTP

**الخطورة:** حرجة — Critical  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `POST /api/mobile-registration`

### خطوات إعادة المشكلة

إرسال:

```json
{
  "name": "Test User",
  "mobile": "رقم جديد",
  "country_calling_code": "+20",
  "password": "Mobile#123",
  "confirm_password": "Mobile#123"
}
```

بدون `firebase_token`.

### النتيجة الحالية

يرجع `HTTP 200` ويتم إنشاء الحساب.

### النتيجة المتوقعة

يجب رفض التسجيل حتى يتم إرسال Firebase ID Token صالح يثبت إتمام OTP لنفس رقم الهاتف.

### التأثير

أي شخص يستطيع إنشاء حساب بأي رقم هاتف لمجرد معرفته.

### الإصلاح المطلوب

- جعل `firebase_token` مطلوبًا في تسجيل الموبايل.
- استخراج `phone_number` من Firebase claims.
- توحيد الرقم ومقارنته بـ`country_calling_code + mobile`.
- عدم الاعتماد على رقم الهاتف المرسل من العميل وحده.

---

## BUG-003 — تسجيل دخول الموبايل يتجاهل كود الدولة

**الخطورة:** عالية — High  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `POST /api/mobile-login`

### المشكلة

الـAPI يتحقق من وجود `country_calling_code` في الطلب، لكنه يبحث في قاعدة البيانات باستخدام `mobile` فقط.

### نتيجة الاختبار

حساب مسجل بكود دولة `+20` نجح تسجيل دخوله عند إرسال `+1` مع نفس الرقم وكلمة المرور.

### النتيجة المتوقعة

يجب البحث باستخدام:

```text
country_calling_code + mobile
```

### التأثير

يمكن حدوث تعارض أو دخول لحساب غير مقصود عند تكرار نفس الرقم المحلي في دول مختلفة.

### الإصلاح المطلوب

تعديل الاستعلام ليشمل:

```php
->where('mobile', $request->mobile)
->where('country_calling_code', $request->country_calling_code)
```

مع إضافة unique index مركب بعد تنظيف البيانات المكررة.

---

## BUG-004 — تغيير كلمة مرور الموبايل لا يثبت ملكية الرقم

**الخطورة:** حرجة — Critical  
**الحالة:** مؤكدة مع BUG-001، ومؤكدة من مراجعة الكود  
**Endpoint:** `POST /api/mobile-reset-password`

### المشكلة

الـEndpoint يعتمد على Firebase UID فقط، ولا يقارن `phone_number` الموجود في Firebase Token برقم الهاتف الخاص بالحساب.

كما أن البحث في `social_logins` لا يقيد النتيجة بـ:

```text
type = phone
```

### النتيجة المتوقعة

- قبول Firebase Token صالح خاص بتسجيل الهاتف فقط.
- مطابقة رقم الهاتف الموثق مع رقم الحساب.
- البحث عن `firebase_id` و`type = phone`.

### التأثير

قد يتم تغيير كلمة مرور حساب غير مقصود عند وجود ربط Firebase خاطئ أو UID مرتبط بنوع Social آخر.

---

## BUG-005 — إعادة تعيين كلمة مرور الموبايل لا تلغي التوكنات القديمة

**الخطورة:** عالية — High  
**الحالة:** مؤكدة من مراجعة الكود  
**Endpoint:** `POST /api/mobile-reset-password`

### المشكلة

بعد تغيير كلمة المرور لا يتم حذف Sanctum tokens الموجودة.

### النتيجة المتوقعة

كل Access وRefresh Tokens القديمة يجب أن تُلغى بعد استرجاع كلمة المرور.

### التأثير

إذا كان الحساب مخترقًا، يظل المهاجم مسجلًا حتى بعد أن يغير صاحب الحساب كلمة المرور.

### الإصلاح المطلوب

بعد نجاح التغيير:

```php
$user->tokens()->delete();
```

---

## BUG-006 — Firebase غير مجهز على السيرفر

**الخطورة:** عالية — High  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `GET /api/firebase-config`

### الاستجابة الحالية

```json
{
  "configured": false,
  "config": null
}
```

### التأثير

التدفقات التالية لن تعمل بصورة حقيقية:

- Google Login عبر Firebase.
- Apple Login عبر Firebase.
- Phone OTP عبر Firebase.
- Firebase phone password reset.

### الإصلاح المطلوب

- إضافة Firebase client settings.
- إضافة Service Account credentials للباك إند.
- التأكد أن Project ID في إعدادات العميل يطابق Service Account.
- إعادة اختبار Google وApple وPhone OTP بعد الإعداد.

---

## BUG-007 — التسجيل قد ينشئ الحساب ثم يرجع خطأ حد الأجهزة

**الخطورة:** عالية — High  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `POST /api/user-signup`

### نتيجة الاختبار

التسجيل ببيانات جهاز رجع:

```text
HTTP 422
لقد وصلت إلى الحد الأقصى المسموح به من الأجهزة (0 أجهزة)
```

لكن الحساب تم إنشاؤه بالفعل، وتم تسجيل الدخول إليه بعد ذلك.

### السبب

إنشاء المستخدم وتنفيذ `DB::commit()` يحدثان قبل التحقق من حد الأجهزة.

### النتيجة المتوقعة

إما:

- رفض العملية كاملة قبل إنشاء الحساب.
- أو إنشاء الحساب وإرجاع نجاح مع معالجة تسجيل الجهاز بصورة منفصلة.

### التأثير

الواجهة تعتقد أن التسجيل فشل، بينما الحساب موجود بالفعل.

### الإصلاح المطلوب

- التحقق من إعداد حد الأجهزة قبل Commit.
- ضبط `default_device_limit` لقيمة صحيحة أكبر من صفر.
- إبقاء التسجيل والتحقق من الجهاز داخل Transaction واحدة إذا كان فشل الجهاز يجب أن يلغي التسجيل.

---

## BUG-008 — الحد الافتراضي للأجهزة يساوي صفرًا

**الخطورة:** عالية — High  
**الحالة:** مؤكدة على السيرفر

### المشكلة

أي طلب Login أو Signup يحتوي على `device_type` و`device_id` يتم رفضه برسالة أن الحد الأقصى هو صفر.

لكن عند حذف بيانات الجهاز ينجح الدخول بسبب backward compatibility.

### التأثير

- العملاء الحديثة التي ترسل Device Metadata تُمنع.
- العملاء القديمة التي لا ترسلها تتجاوز نظام الحد بالكامل.

### الإصلاح المطلوب

- ضبط `default_device_limit` مثلًا إلى 3.
- وضع سياسة واضحة عند غياب بيانات الجهاز.
- عدم السماح بتجاوز Device Limit بمجرد حذف `device_id`.

---

## BUG-009 — التسجيل بنفس الإيميل يتحول إلى Login

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `POST /api/user-signup`

### المشكلة

إعادة التسجيل بإيميل موجود وكلمة المرور الصحيحة ترجع:

```text
HTTP 200
User logged-in successfully
```

### النتيجة المتوقعة

Endpoint التسجيل يجب أن يرجع:

```text
HTTP 422
Email already registered
```

ويتم استخدام Login endpoint للدخول.

### التأثير

- عقد API غير واضح.
- صعوبة قياس التسجيلات الجديدة.
- احتمالية تكرار Tracking event الخاص بالتسجيل.

---

## BUG-010 — الإيميل ليس Unique بشكل مستقل في قاعدة البيانات

**الخطورة:** عالية — High  
**الحالة:** مؤكدة من مراجعة Migration

### المشكلة

الموجود هو unique index مركب:

```text
(email, mobile)
```

وليس unique index على `email` وحده.

مع كون `mobile` قابلًا لـNULL، يمكن أن توجد حسابات متعددة بنفس الإيميل حسب سلوك قاعدة البيانات.

### التأثير

- Login أو reset قد يتعامل مع سجل غير متوقع.
- `updateOrCreate(['email' => ...])` قد يحدث سجلًا غير مقصود.

### الإصلاح المطلوب

- تنظيف الإيميلات المكررة.
- تخزين الإيميل بصورة normalized lowercase/trimmed.
- إضافة unique index على normalized email.

---

## BUG-011 — Social provider غير مدعوم يرجع 500

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة على السيرفر  
**Endpoint:** `POST /api/social-login/{provider}`

### نتيجة الاختبار

إرسال provider غير موجود مثل:

```text
not-a-provider
```

يرجع:

```text
HTTP 500
Error Occurred
```

### النتيجة المتوقعة

```text
HTTP 422
Unsupported social provider
```

### الإصلاح المطلوب

التحقق من allow-list قبل استدعاء Firebase/Socialite:

```php
in_array($provider, ['google', 'apple'], true)
```

---

## BUG-012 — Social Login لا يستخدم نظام Access/Refresh Token الموحد

**الخطورة:** عالية — High  
**الحالة:** مؤكدة من مراجعة الكود  
**Endpoint:** `POST /api/social-login/{provider}`

### المشكلة

Email/mobile login يستخدمان Token Pair:

- Access token قصير العمر.
- Refresh token طويل العمر.

لكن `SocialLoginApiController` ينشئ Token واحدًا بالطريقة القديمة، بدون Refresh Token وبدون expiration موحد.

### التأثير

- سلوك مختلف حسب طريقة الدخول.
- Social clients لا تستطيع استخدام Refresh flow نفسه.
- سياسة Single Session لا تطبق بصورة متسقة.

### الإصلاح المطلوب

استخدام خدمة مركزية واحدة لإصدار Access/Refresh Token Pair في كل طرق الدخول.

---

## BUG-013 — تعدد وتداخل Social Login contracts

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة من مراجعة الكود

### المشكلة

Google/Apple يمكن التعامل معهما عبر:

- `POST /api/user-signup`
- `POST /api/user-login`
- `POST /api/social-login/{provider}`

وكل مسار له منطق وتوكنات وربط حسابات مختلف.

### التأثير

- احتمالية إنشاء روابط مكررة.
- اختلاف الاستجابات.
- صعوبة صيانة واختبار Social Login.

### الإصلاح المطلوب

اعتماد Endpoint واحد فقط لـSocial Authentication، يتولى التسجيل أو الربط أو الدخول.

---

## BUG-014 — Firebase signup يعتمد على بيانات غير موثقة من العميل

**الخطورة:** عالية — High  
**الحالة:** مؤكدة من مراجعة الكود

### المشكلة

بعض Firebase signup flows تستخدم `email` و`name` المرسلين في body بدل استخراج القيم الموثقة من Firebase claims.

### النتيجة المتوقعة

الهوية الأساسية مثل UID والإيميل ورقم الهاتف يجب أن تأتي من التوكن الموثق فقط.

### التأثير

يمكن حدوث اختلاف بين صاحب Firebase Token وبين الإيميل المخزن محليًا.

---

## BUG-015 — مسارات Web Authentication غير موجودة

**الخطورة:** عالية حسب متطلبات المنتج  
**الحالة:** مؤكدة على السيرفر

### المسارات المختبرة

```text
/login
/register
/forgot-password
```

كلها ترجع `HTTP 404`.

### الملاحظة

يوجد `AuthController` وBlade login view في المشروع، لكن لا توجد routes مسجلة تصل إليهما.

### التأثير

التسجيل والدخول واسترجاع كلمة المرور عن طريق الموقع غير متاحين حاليًا.

### الإصلاح المطلوب

إضافة Web routes واضحة للأعضاء، مع فصل Admin Login عن Member Login.

---

## BUG-016 — قواعد كلمة المرور غير موحدة

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة من مراجعة الكود

### القواعد الحالية

- Email signup: ستة أحرف.
- Mobile registration: ستة أحرف.
- Email reset: ستة أحرف.
- Mobile reset: ستة أحرف.
- Change password: ثمانية أحرف.

### التأثير

يمكن للمستخدم تعيين كلمة مرور يقبلها reset ثم ترفضها شاشات أخرى أو تخالف سياسة النظام.

### الإصلاح المطلوب

استخدام Password Rule مركزي وموحد لكل التدفقات.

---

## BUG-017 — أسماء حقول تأكيد كلمة المرور غير موحدة

**الخطورة:** منخفضة — Low  
**الحالة:** مؤكدة من مراجعة الكود

### الحقول الحالية

- Signup/mobile reset يستخدمان `confirm_password`.
- Email reset يستخدم `password_confirmation`.
- Change password يستخدم `new_password_confirmation`.

### التأثير

زيادة أخطاء التكامل بين الويب والموبايل والـAPI.

### الإصلاح المطلوب

اعتماد Laravel convention:

```text
password
password_confirmation
```

أو توثيق contract موحد لكل endpoints.

---

## BUG-018 — OTP البريد قابل لإعادة التحقق قبل تنفيذ Reset

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة من مراجعة الكود

### المشكلة

`verify-reset-code` يثبت صحة OTP لكنه لا يستهلكه ولا يصدر Reset Token منفصلًا.

يظل نفس OTP صالحًا حتى:

- تنفيذ reset-password.
- انتهاء مدة 15 دقيقة.
- إرسال OTP جديد.

### النتيجة المتوقعة

بعد التحقق يمكن إصدار Reset Authorization قصير العمر وأحادي الاستخدام، أو ربط التحقق بجلسة reset آمنة.

---

## BUG-019 — لا يوجد حد محاولات OTP لكل حساب

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة من مراجعة الكود

### المشكلة

يوجد Route throttling حسب الطلب/IP، لكن لا يوجد failed-attempt counter أو lockout مرتبط بالإيميل نفسه.

### التأثير

يمكن توزيع المحاولات على أكثر من IP لتجربة OTP.

### الإصلاح المطلوب

- عداد محاولات لكل email/reset challenge.
- إبطال OTP بعد عدد محدود من المحاولات.
- تسجيل محاولات الفشل والتنبيه عند السلوك المشبوه.

---

## BUG-020 — حسابات Social يمكنها إنشاء كلمة مرور محلية عبر Forgot Password

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة من مراجعة الكود، وتحتاج قرار منتج

### المشكلة

الحساب الذي أُنشئ عبر Google/Apple يحصل على كلمة مرور محلية عشوائية. Email forgot-password يمكنه بعد ذلك وضع كلمة مرور محلية للحساب.

### السؤال المطلوب حسمه

هل تريدون السماح لحساب Social بالتحول إلى حساب Email + Password؟

### الإصلاح المقترح

- إذا كان مسموحًا: توثيق التدفق وتسميته “Set password”.
- إذا لم يكن مسموحًا: منع reset للحسابات Social-only.

---

## BUG-021 — ApiResponseService يستخدم `exit()`

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة أثناء تشغيل الاختبارات

### المشكلة

الخدمة تنفذ:

```php
$jsonResponse->send();
exit();
```

### التأثير

- توقف PHPUnit runner أثناء الاختبارات.
- صعوبة تنفيذ Middleware cleanup وevents.
- صعوبة كتابة Feature tests مستقرة.

### الإصلاح المطلوب

إرجاع `JsonResponse` من الخدمة والـController بدل إرسال الاستجابة وإنهاء عملية PHP يدويًا.

---

## BUG-022 — تغطية اختبارات Authentication غير كافية

**الخطورة:** متوسطة — Medium  
**الحالة:** مؤكدة

### الاختبارات الناقصة

- Email signup ناجح.
- Email login ناجح وفاشل بصورة كاملة.
- Duplicate registration.
- Mobile registration مع وبدون OTP.
- مطابقة كود الدولة.
- Mobile password reset وإلغاء الجلسات.
- Google/Apple Firebase flows.
- Socialite flow.
- Unsupported provider.
- Device limit behavior.
- Inactive/deleted users في كل طرق الدخول.

---

## BUG-023 — لا يوجد Logout endpoint مباشر للتوكن الحالي

**الخطورة:** منخفضة — Low  
**الحالة:** مؤكدة من مراجعة الـroutes

### المشكلة

لتسجيل الخروج يجب:

1. طلب active sessions.
2. معرفة ID الخاص بالتوكن الحالي.
3. استدعاء logout session باستخدام ID.

### النتيجة المتوقعة

وجود:

```text
POST /api/logout
```

لحذف `currentAccessToken()` مباشرة.

---

## BUG-024 — Maintenance routes عامة وخطرة

**الخطورة:** حرجة في Production — Critical  
**الحالة:** مؤكدة من مراجعة routes  
**المسارات:**

```text
/migrate
/seed-superadmin
/clear
/storage-link
```

### المشكلة

المسارات موجودة في Web routes بدون حماية Authentication/Authorization واضحة.

### التأثير

- تشغيل migrations عن بعد.
- إنشاء Super Admin ببيانات معروفة.
- مسح cache/config.
- تعديل storage link.

### الإصلاح المطلوب

حذف هذه المسارات من Production فورًا، وتنفيذ العمليات عبر CLI/Deployment فقط.

---

## النتائج السليمة التي تم التحقق منها

هذه النقاط عملت بالشكل المتوقع:

- Email login بكلمة مرور صحيحة.
- رفض كلمة المرور الخاطئة.
- إصدار Access وRefresh Token.
- رفض استخدام Access Token لتنفيذ Refresh.
- تدوير Token Pair وإلغاء التوكنات القديمة.
- عرض Active Sessions.
- رفض Old Password خاطئة عند تغيير كلمة المرور.
- إلغاء كل التوكنات بعد Change Password.
- Login بكلمة المرور الجديدة.
- Logout للجلسة الحالية.
- منع المستخدم العادي من Admin Login.
- Forgot password يعيد رسالة عامة للإيميل الموجود وغير الموجود.
- رفض Email OTP خاطئ.
- رفض Google access token غير صالح بـ`401`.
- رفض Social Login بدون token بـ`422`.

---

## ترتيب الإصلاح المقترح

### فورًا قبل أي اختبار أو نشر جديد

1. حذف `skilso-no-firebase`.
2. حماية أو حذف Maintenance routes.
3. فرض Firebase OTP في Mobile Registration وReset.
4. مطابقة Firebase phone claim مع الحساب.
5. إصلاح Mobile Login ليستخدم كود الدولة.

### المرحلة التالية

6. إصلاح Device Limit والـtransaction.
7. توحيد Social Login وAccess/Refresh Tokens.
8. رفض Duplicate signup.
9. إضافة unique indexes الصحيحة.
10. إلغاء الجلسات بعد Mobile Reset.
11. تجهيز Firebase على السيرفر.

### بعد إغلاق المشاكل الأمنية

12. إنشاء Web Authentication routes.
13. توحيد Password rules والحقول.
14. تحسين Email OTP lifecycle.
15. إعادة تصميم `ApiResponseService`.
16. إضافة Feature tests كاملة.

---

## بيانات الاختبار التي أُنشئت

تم إنشاء حسابات آلية بأسماء وإيميلات تبدأ غالبًا بـ:

```text
Codex Auth Test
Codex Mobile No OTP
Codex Firebase Sentinel Test
Codex Fake Google Test
codex.auth.
codex.fake.google.
```

يُنصح بحذف هذه الحسابات من قاعدة بيانات السيرفر بعد الانتهاء من التحقيق.
