---
description: إضافة API endpoint جديد
---

# خطوات إضافة API Endpoint جديد

## 1. تحديد المتطلبات
- ما هو الـ HTTP method (GET, POST, PUT, DELETE)?
- هل يحتاج authentication?
- ما هي الـ validation rules?

## 2. إنشاء/تعديل Controller
```bash
# إنشاء controller جديد
php artisan make:controller API/NewApiController
```

الملفات المعتادة:
- `app/Http/Controllers/API/` للـ API controllers
- `app/Http/Controllers/ApiController.php` للـ general APIs

## 3. إضافة Route
ملف: `routes/api.php`

```php
// بدون authentication
Route::get('endpoint', [Controller::class, 'method']);

// مع authentication
Route::middleware('auth:sanctum')->group(function () {
    Route::get('endpoint', [Controller::class, 'method']);
});
```

## 4. إنشاء/تعديل Model (إذا لزم)
```bash
php artisan make:model ModelName -m
```

## 5. الاختبار
// turbo
```bash
php artisan route:list --path=api/endpoint-name
```

```bash
curl -X GET 'http://127.0.0.1:8000/api/endpoint' \
  --header 'Authorization: Bearer TOKEN'
```

## 6. تحديث Postman Collection
أضف الـ endpoint الجديد لـ `LMS_API_Collection.postman_collection.json`
