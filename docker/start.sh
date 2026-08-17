#!/bin/bash
set -e

# Ensure storage directories and log paths exist with proper permissions
mkdir -p /var/www/html/storage/logs /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache /var/log/supervisor /var/log/nginx /var/run /etc/supervisor/conf.d
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run 2>/dev/null || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# If PORT is specified in environment, adjust nginx listen port
if [ -n "$PORT" ] && [ "$PORT" != "80" ]; then
    sed -i "s/listen 80 default_server;/listen $PORT default_server;/g" /etc/nginx/sites-available/default 2>/dev/null || true
    sed -i "s/listen \[::\]:80 default_server;/listen \[::\]:$PORT default_server;/g" /etc/nginx/sites-available/default 2>/dev/null || true
fi

# Start supervisor in foreground
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n
