# Build Stage (PHP 8.4)
FROM php:8.4-fpm-alpine AS builder

RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    linux-headers

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# Production Runtime Stage (PHP 8.4 + Nginx + Supervisord)
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng \
    libjpeg-turbo \
    freetype \
    libwebp \
    libzip \
    icu-libs \
    oniguruma

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache

WORKDIR /var/www/html

COPY --from=builder /var/www/html /var/www/html

# Generate Nginx config inline (Zero external file dependencies)
RUN echo 'server {' > /etc/nginx/http.d/default.conf \
    && echo '    listen 80;' >> /etc/nginx/http.d/default.conf \
    && echo '    listen [::]:80;' >> /etc/nginx/http.d/default.conf \
    && echo '    server_name _;' >> /etc/nginx/http.d/default.conf \
    && echo '    root /var/www/html/public;' >> /etc/nginx/http.d/default.conf \
    && echo '    add_header X-Frame-Options "SAMEORIGIN";' >> /etc/nginx/http.d/default.conf \
    && echo '    add_header X-Content-Type-Options "nosniff";' >> /etc/nginx/http.d/default.conf \
    && echo '    index index.php;' >> /etc/nginx/http.d/default.conf \
    && echo '    charset utf-8;' >> /etc/nginx/http.d/default.conf \
    && echo '    location / { try_files $uri $uri/ /index.php?$query_string; }' >> /etc/nginx/http.d/default.conf \
    && echo '    location = /favicon.ico { access_log off; log_not_found off; }' >> /etc/nginx/http.d/default.conf \
    && echo '    location = /robots.txt  { access_log off; log_not_found off; }' >> /etc/nginx/http.d/default.conf \
    && echo '    error_page 404 /index.php;' >> /etc/nginx/http.d/default.conf \
    && echo '    location ~ \.php$ {' >> /etc/nginx/http.d/default.conf \
    && echo '        fastcgi_pass 127.0.0.1:9000;' >> /etc/nginx/http.d/default.conf \
    && echo '        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;' >> /etc/nginx/http.d/default.conf \
    && echo '        include fastcgi_params;' >> /etc/nginx/http.d/default.conf \
    && echo '        fastcgi_hide_header X-Powered-By;' >> /etc/nginx/http.d/default.conf \
    && echo '    }' >> /etc/nginx/http.d/default.conf \
    && echo '    location ~ /\.(?!well-known).* { deny all; }' >> /etc/nginx/http.d/default.conf \
    && echo '}' >> /etc/nginx/http.d/default.conf

# Generate Supervisor config inline
RUN echo '[supervisord]' > /etc/supervisor/conf.d/supervisord.conf \
    && echo 'nodaemon=true' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'user=root' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'logfile=/var/log/supervisord.log' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'pidfile=/var/run/supervisord.pid' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo '[program:php-fpm]' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'command=php-fpm -F' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo '[program:nginx]' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'command=nginx -g "daemon off;"' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf \
    && echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf

# Generate custom PHP settings inline
RUN echo 'upload_max_filesize = 64M' > /usr/local/etc/php/conf.d/custom.ini \
    && echo 'post_max_size = 64M' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'max_execution_time = 300' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'expose_php = Off' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'opcache.enable = 1' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'opcache.validate_timestamps = 0' >> /usr/local/etc/php/conf.d/custom.ini

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
