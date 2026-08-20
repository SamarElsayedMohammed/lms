#!/bin/bash
# DO NOT use "set -e" — a failed artisan/migrate must NOT kill PID 1.
# Coolify healthchecks the container while this script still runs. Open nginx first.

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

umask 0002

# Fast permissions. Avoid chmod -R on huge persistent log volumes (delays HTTP).
chown www-data:www-data \
    /var/www/html/storage \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    /var/log \
    /var/run 2>/dev/null || true
chmod 775 \
    /var/www/html/storage \
    /var/www/html/storage/logs \
    /var/www/html/storage/framework \
    /var/www/html/bootstrap/cache 2>/dev/null || true

touch /var/www/html/storage/logs/laravel.log 2>/dev/null || true
chown www-data:www-data /var/www/html/storage/logs/laravel.log 2>/dev/null || true
chmod 664 /var/www/html/storage/logs/laravel.log 2>/dev/null || true

# Bind nginx to Coolify PORT before supervisord starts listeners.
if [ -n "$PORT" ] && [ "$PORT" != "80" ]; then
    sed -i "s/listen 80 default_server;/listen $PORT default_server;/g" \
        /etc/nginx/sites-available/default 2>/dev/null || true
    sed -i "s/listen \[::\]:80 default_server;/listen [::]:$PORT default_server;/g" \
        /etc/nginx/sites-available/default 2>/dev/null || true
fi

# HTTP must be up before migrate. laravel-boot in supervisord runs migrate in the background.
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n
