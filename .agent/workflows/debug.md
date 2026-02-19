---
description: تصحيح الأخطاء وحلها
---

# خطوات Debug وحل المشاكل

## 1. فهم الخطأ
- اقرأ رسالة الخطأ بعناية
- حدد نوع الخطأ (validation, server, database, etc.)

## 2. فحص الـ Logs
// turbo
```bash
tail -50 storage/logs/laravel.log
```

```bash
# مسح الـ cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## 3. اختبار الـ API
```bash
curl -X METHOD 'http://127.0.0.1:8000/api/endpoint' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer TOKEN' \
  --data '{"key": "value"}'
```

## 4. البحث عن الكود المسبب
```bash
# البحث عن رسالة الخطأ
grep -r "error message" app/

# البحث عن function معينة
grep -rn "functionName" app/
```

## 5. فحص قاعدة البيانات
```bash
php artisan tinker
# ثم
DB::table('table_name')->where('id', 1)->first();
```

## 6. التحقق من Environment
// turbo
```bash
cat .env | grep -E "^(APP_|DB_|FIREBASE_)"
```

## 7. توثيق الحل
بعد الحل، أضف للـ `projectContext.md`:
- وصف المشكلة
- سبب المشكلة
- الحل المطبق
