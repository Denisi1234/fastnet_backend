# Use official PHP 8.4 FPM Alpine image
FROM php:8.4-fpm-alpine

# Step 1: Get the PHP Extension Installer (auto-resolves all APK deps)
COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Step 2: Install PHP extensions (IPE handles all APK dev headers + cleanup automatically)
RUN install-php-extensions \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    gd \
    zip \
    bcmath \
    intl \
    exif \
    opcache \
    pcntl

# Step 3: Install system tools (nginx, supervisor, git, unzip)
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    unzip \
    && mkdir -p /etc/supervisor.d /var/log/supervisor /run/nginx

# Step 4: Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Step 5: Set working directory and copy app
WORKDIR /var/www/html
COPY . .

# Step 6: Install Laravel dependencies (prod only) and rebuild package
# discovery from installed packages — never ship the repo's stale
# bootstrap/cache (it references dev-only providers like Pail → 500s).
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && rm -f bootstrap/cache/services.php bootstrap/cache/packages.php \
    && php artisan package:discover --ansi || true

# Step 7: Copy config files from docker/ folder
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99_custom.ini

# Step 8: Set storage permissions
RUN mkdir -p storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
