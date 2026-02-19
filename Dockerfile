FROM php:8.3-fpm

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libxpm-dev \
    libicu-dev \
    libpq-dev \
    libsodium-dev \
    ffmpeg \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    opcache \
    sodium

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install PCOV
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=1" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini \
    && echo "pcov.clobber=1" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js and npm
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Copy application files
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Configure PHP
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "upload_max_filesize = 50M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "post_max_size = 50M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "memory_limit = 512M" >> "$PHP_INI_DIR/conf.d/uploads.ini" \
    && echo "max_execution_time = 300" >> "$PHP_INI_DIR/conf.d/uploads.ini"

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
