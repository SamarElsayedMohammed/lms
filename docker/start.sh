#!/bin/bash
# DO NOT use "set -e" here — a single failed command must NOT kill the whole container

# ── Ensure all required directories exist ────────────────────────────────────
mkdir -p \
    /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache \
    /var/log/supervisor \
    /var/log/nginx \
    /var/run \
    /etc/supervisor/conf.d

# ── Set umask to ensure group writeability for www-data ───────────────────────
umask 0002

# ── Permissions (must run BEFORE artisan — www-data needs write access) ───────
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run 2>/dev/null || true

# ── Laravel runtime optimizations ────────────────────────────────────────────
cd /var/www/html

# Clear stale caches that may cause issues after fresh deploy
php artisan config:clear   2>/dev/null || true
php artisan cache:clear    2>/dev/null || true
php artisan route:clear    2>/dev/null || true
php artisan view:clear     2>/dev/null || true

# Re-cache for performance
php artisan config:cache   2>/dev/null || true
php artisan route:cache    2>/dev/null || true

# Run pending migrations (safe in staging — remove in strict production)
php artisan migrate --force >> /var/log/supervisor/migrate.log 2>&1 || echo "migrate failed; see /var/log/supervisor/migrate.log"

# ── Re-apply ownership AFTER artisan. Never rm/truncate logs (they must survive restarts). ──
# touch creates laravel.log only if missing; it does not wipe existing content.
touch /var/www/html/storage/logs/laravel.log 2>/dev/null || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run 2>/dev/null || true
chmod 664 /var/www/html/storage/logs/laravel.log 2>/dev/null || true

# ── Nginx port override (if Coolify sets PORT) ────────────────────────────────
if [ -n "$PORT" ] && [ "$PORT" != "80" ]; then
    sed -i "s/listen 80 default_server;/listen $PORT default_server;/g" \
        /etc/nginx/sites-available/default 2>/dev/null || true
    sed -i "s/listen \[::\]:80 default_server;/listen [::]:$PORT default_server;/g" \
        /etc/nginx/sites-available/default 2>/dev/null || true
fi

# ── Start supervisor (foreground, keeps container alive) ──────────────────────
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n
