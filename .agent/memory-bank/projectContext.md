# LMS v1.0.2 - Project Context (Memory Bank)

> **آخر تحديث:** 2026-02-08
> **المشروع:** Learning Management System

---

## 🎯 نظرة عامة على المشروع

**الوصف:** نظام إدارة تعلم متكامل (LMS) مبني بـ Laravel
**الإطار:** Laravel 10+
**قاعدة البيانات:** MySQL
**الـ API:** RESTful مع Sanctum Authentication

---

## 📁 هيكل المشروع الأساسي

```
lms-v1.0.2/
├── app/
│   ├── Http/Controllers/API/    # API Controllers
│   ├── Models/                   # Eloquent Models
│   └── Services/                 # Business Logic Services
├── routes/
│   ├── api.php                  # API Routes (~559 lines, 150+ endpoints)
│   └── web.php                  # Web Routes
├── config/
│   └── firebase.php             # Firebase Configuration
├── storage/app/firebase/        # Firebase Credentials
└── .agent/                      # AI Agent Configuration
```

---

## 🔐 نظام المصادقة

| Type | Method | Endpoint |
|------|--------|----------|
| Email/Password | POST | `/api/user-signup`, `/api/user-login` |
| Mobile | POST | `/api/mobile-login`, `/api/mobile-registration` |
| Social (Google/Apple) | POST | with `firebase_token` |

**المتطلبات:**
- Firebase credentials في `storage/app/firebase/`
- Environment variable: `FIREBASE_CREDENTIALS`

---

## 👥 الأدوار في النظام

| Role | Description |
|------|-------------|
| `general_user` | المستخدم العادي (طالب) |
| `instructor` | المدرس |
| `admin` | المدير |
| `team` | عضو فريق المدرس |

---

## 📊 الوحدات الرئيسية

### 1. الكورسات (Courses)
- CRUD كامل للكورسات
- Chapters و Curriculum (Lectures, Quizzes, Assignments, Resources)
- تتبع التقدم للطلاب

### 2. الطلبات والمحفظة (Orders & Wallet)
- نظام سلة الشراء
- أكواد الخصم (Promo Codes)
- المحفظة والسحب

### 3. الشهادات (Certificates)
- شهادة إتمام الكورس
- شهادة اجتياز الاختبار

### 4. نظام التقييم (Ratings)
- تقييم الكورسات
- تقييم المدرسين

---

## 🛠 الأوامر المهمة

```bash
# تشغيل السيرفر
php artisan serve

# مسح الـ cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# الـ migrations
php artisan migrate

# الـ Postman Collection
# ملف: LMS_API_Collection.postman_collection.json
```

---

## ⚠️ ملاحظات مهمة

### Firebase Configuration
- **المسار:** `storage/app/firebase/{filename}.json`
- **الـ .env:** `FIREBASE_CREDENTIALS="firebase/{filename}.json"`

### المشاكل المعروفة
1. ~~Firebase Configuration Error~~ ✅ (تم حلها)

---

## 📝 سجل التغييرات

| التاريخ | التغيير |
|--------|---------|
| 2026-02-08 | إصلاح Firebase credentials path |
| 2026-02-08 | إنشاء Postman Collection (150+ endpoints) |
