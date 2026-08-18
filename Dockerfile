# ─────────────────────────────────────────────────────────────────────────────
# Application image with PHP 8.3 FPM, Nginx, and static ffmpeg
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm

WORKDIR /var/www/html

# ─── 1. System packages (minimal) ─────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        nginx \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

# ─── 2. Copy static ffmpeg/ffprobe binaries (multi-arch, fully self-contained) ─
COPY --from=mwader/static-ffmpeg:latest /ffmpeg  /usr/local/bin/ffmpeg
COPY --from=mwader/static-ffmpeg:latest /ffprobe /usr/local/bin/ffprobe

# ─── 3. PHP extensions via fast binary installer ─────────────────────────────
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_mysql \
    redis \
    bcmath \
    gd \
    zip \
    intl \
    opcache \
    sodium \
    pcntl \
    exif

# ─── 4. Composer ─────────────────────────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ─── 5. Copy application ─────────────────────────────────────────────────────
COPY . /var/www/html

# ─── 6. Create ALL required directories before composer runs ──────────────────
RUN mkdir -p \
        bootstrap/cache \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R 775 bootstrap/cache storage

# ─── 7. Composer install ─────────────────────────────────────────────────────
RUN APP_ENV=production composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs \
        --no-scripts \
    && composer dump-autoload --optimize --ignore-platform-reqs

# ─── 8. Generate package manifest (no DB needed, safe at build time) ─────────
RUN php artisan package:discover --ansi 2>/dev/null || true

# ─── 9. PHP & FPM config ─────────────────────────────────────────────────────
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf \
        "upload_max_filesize = 50M\npost_max_size = 50M\nmemory_limit = 256M\nmax_execution_time = 60\ndisplay_errors = Off\nlog_errors = On\nopcache.enable = 1\nopcache.memory_consumption = 64\nopcache.max_accelerated_files = 10000\n" \
        > "$PHP_INI_DIR/conf.d/uploads.ini"
COPY docker/php/zz-skillso-fpm.conf /usr/local/etc/php-fpm.d/zz-skillso.conf

# ─── Symlink /app → /var/www/html (Coolify pre-deployment commands use /app) ──
RUN ln -sf /var/www/html /app

# ─── 10. Final permissions ────────────────────────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# ─── 11. Nginx ────────────────────────────────────────────────────────────────
COPY docker/nginx/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# ─── 12. Supervisor ───────────────────────────────────────────────────────────
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# ─── 13. Startup script ───────────────────────────────────────────────────────
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# ─── Health check ─────────────────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health 2>/dev/null || curl -f http://localhost/ 2>/dev/null || exit 1

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
