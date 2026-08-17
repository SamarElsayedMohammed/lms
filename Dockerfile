FROM php:8.3-fpm

WORKDIR /var/www/html

# Install minimal system dependencies + Nginx + Supervisor + ffmpeg (without desktop GUI packages)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    nginx \
    supervisor \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install official PHP extension installer for fast binary installations
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install and enable PHP extensions in seconds
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

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html

# Install composer dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# Set permissions
RUN mkdir -p /var/www/html/storage/logs /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache /var/log/supervisor /var/log/nginx /var/run /etc/supervisor/conf.d \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache /var/log /var/run \
    && chown -R www-data:www-data /var/www/html

# Configure PHP
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "upload_max_filesize = 50M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "post_max_size = 50M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "memory_limit = 512M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "max_execution_time = 300" >> "$PHP_INI_DIR/conf.d/uploads.ini"

# Configure Nginx
COPY docker/nginx/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# Start script
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 80 9000

CMD ["/usr/local/bin/start.sh"]
