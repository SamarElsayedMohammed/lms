FROM php:8.3-fpm

WORKDIR /var/www/html

# ─── 1. System packages (minimal, no desktop GUI) ───────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    nginx \
    supervisor \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# ─── 2. PHP extensions via fast binary installer ─────────────────────────────
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

# ─── 3. Composer ─────────────────────────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ─── 4. Copy application ─────────────────────────────────────────────────────
COPY . /var/www/html

# ─── 5. Create ALL required directories before composer runs ─────────────────
#        (artisan package:discover needs bootstrap/cache to be writable)
RUN mkdir -p \
        bootstrap/cache \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
    && chmod -R 777 bootstrap/cache storage

# ─── 6. Composer install ─────────────────────────────────────────────────────
#        --no-scripts  → skip post-autoload artisan calls that need a real DB
#        We run package:discover manually afterward
RUN APP_ENV=production composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs \
        --no-scripts \
    && composer dump-autoload --optimize --ignore-platform-reqs

# ─── 7. Generate package manifest (no DB needed, safe at build time) ─────────
RUN php artisan package:discover --ansi 2>/dev/null || true

# ─── 8. PHP config ───────────────────────────────────────────────────────────
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf \
        "upload_max_filesize = 50M\npost_max_size = 50M\nmemory_limit = 512M\nmax_execution_time = 300\n" \
        > "$PHP_INI_DIR/conf.d/uploads.ini"

# ─── Symlink /app → /var/www/html (Coolify pre-deployment commands use /app) ──
RUN ln -sf /var/www/html /app

# ─── 9. Final permissions ─────────────────────────────────────────────────────
RUN chmod -R 777 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

# ─── 10. Nginx ───────────────────────────────────────────────────────────────
COPY docker/nginx/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# ─── 11. Supervisor ──────────────────────────────────────────────────────────
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# ─── 12. Startup script ──────────────────────────────────────────────────────
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# ─── Health check (lets Coolify/Docker know the app is alive) ────────────────
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health 2>/dev/null || curl -f http://localhost/ 2>/dev/null || exit 1

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
