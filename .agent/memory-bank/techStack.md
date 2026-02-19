# Tech Stack - المكدس التقني

---

## 🔧 Backend

| Technology | Version | Purpose |
|-----------|---------|---------|
| PHP | 8.1+ | Core Language |
| Laravel | 10.x | Framework |
| MySQL | 8.0 | Database |
| Laravel Sanctum | - | API Authentication |
| Firebase Admin SDK | - | Social Auth & FCM |

---

## 📦 حزم Laravel المهمة

```json
{
  "laravel/sanctum": "API tokens",
  "kreait/laravel-firebase": "Firebase integration",
  "spatie/laravel-permission": "Roles & Permissions",
  "intervention/image": "Image processing"
}
```

---

## 🗄 قاعدة البيانات

### الجداول الرئيسية

| Table | Purpose |
|-------|---------|
| `users` | المستخدمين |
| `courses` | الكورسات |
| `course_chapters` | فصول الكورسات |
| `orders` | الطلبات |
| `instructors` | بيانات المدرسين |
| `social_logins` | ربط Firebase IDs |

---

## 🔌 الـ APIs الخارجية

| Service | Purpose | Config |
|---------|---------|--------|
| Firebase Auth | Social Login | `config/firebase.php` |
| Razorpay | Payments | `.env` |
| FCM | Push Notifications | Firebase |

---

## 📁 مسارات الملفات المهمة

```
storage/app/firebase/     → Firebase credentials
storage/app/public/       → Public uploads
public/storage/           → Symlinked uploads
```
