---
description: اختبار APIs والتحقق من العمل
---

# خطوات الاختبار والتحقق

## 1. اختبار API واحد
```bash
# GET request
curl -X GET 'http://127.0.0.1:8000/api/endpoint'

# POST request with JSON
curl -X POST 'http://127.0.0.1:8000/api/endpoint' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer TOKEN' \
  --data '{"key": "value"}'
```

## 2. التحقق من Routes
// turbo
```bash
php artisan route:list --path=api
```

## 3. اختبار عبر Postman
1. استورد الـ collection: `LMS_API_Collection.postman_collection.json`
2. اضبط المتغيرات:
   - `{{base_url}}` = `http://127.0.0.1:8000/api`
   - `{{token}}` = Bearer token بعد login

## 4. اختبار Database
```bash
php artisan tinker --execute="echo App\Models\User::count();"
```

## 5. التحقق من الـ Logs
// turbo
```bash
tail -20 storage/logs/laravel.log
```

## 6. اختبارات Unit (إذا موجودة)
```bash
php artisan test
# أو
./vendor/bin/phpunit
```

## 7. قائمة التحقق النهائية
- [ ] الـ API يرجع response صحيح
- [ ] الـ validation يعمل
- [ ] الـ authentication يعمل (إذا مطلوب)
- [ ] لا توجد errors في الـ logs
- [ ] الـ data تُحفظ بشكل صحيح في DB
