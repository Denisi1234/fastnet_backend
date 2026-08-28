FROM php:8.4-fpm-alpine

# Install pre-built Alpine PHP extensions, Nginx, Supervisor, Composer & Git
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    unzip \
    php84 \
    php84-fpm \
    php84-pdo_mysql \
    php84-mbstring \
    php84-gd \
    php84-zip \
    php84-bcmath \
    php84-intl \
    php84-exif \
    php84-opcache \
    php84-curl \
    php84-xml \
    php84-tokenizer \
    php84-session \
    php84-fileinfo \
    php84-iconv \
    php84-ctype \
    php84-sodium \
    php84-openssl \
    php84-phar \
    php84-simplexml

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . .

# Run composer install with ignore platform reqs
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# Generate Nginx config inline
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
    && echo 'command=php-fpm84 -F || php-fpm -F' >> /etc/supervisor/conf.d/supervisord.conf \
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
RUN echo 'upload_max_filesize = 64M' > /etc/php84/conf.d/99_custom.ini 2>/dev/null || true \
    && echo 'post_max_size = 64M' >> /etc/php84/conf.d/99_custom.ini 2>/dev/null || true \
    && echo 'memory_limit = 512M' >> /etc/php84/conf.d/99_custom.ini 2>/dev/null || true \
    && echo 'max_execution_time = 300' >> /etc/php84/conf.d/99_custom.ini 2>/dev/null || true

RUN mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R nobody:nobody /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
