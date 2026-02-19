---
description: تشغيل السيرفر ومسح الـ cache
---

# تشغيل السيرفر

## تشغيل Development Server
// turbo
```bash
php artisan serve
```

## مسح جميع الـ Cache
// turbo
```bash
php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear
```

## التحقق من حالة السيرفر
// turbo
```bash
curl -s http://127.0.0.1:8000/api/dashboard-test
```
