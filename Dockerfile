# syntax=docker/dockerfile:1

# ── Stage 1: frontend build ─────────────────────────────────────────────────
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.* ./
COPY jsconfig.json* tsconfig.json* ./
RUN npm run build

# ── Stage 2: PHP dependencies ───────────────────────────────────────────────
# composer install --optimize-autoloader needs every autoload path declared
# in composer.json (app/ PSR-4 root, database/ factories+seeders, and the
# app/Modules/Integrations/database/seeders/ classmap) present to scan.
FROM composer:2 AS vendor
WORKDIR /app
COPY app app
COPY database database
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --optimize-autoloader \
    --ignore-platform-reqs

# ── Stage 3: runtime image ──────────────────────────────────────────────────
FROM php:8.2-apache AS runtime

# System packages + PHP extensions the app actually uses (redis queue/cache,
# mysql, gd for image handling, zip for exports/imports, intl for formatting).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        supervisor \
        cron \
        git \
        unzip \
        curl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpng-dev libjpeg62-turbo-dev libwebp-dev libonig-dev libxml2-dev libzip-dev libicu-dev libcurl4-openssl-dev libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Apache: serve Laravel's public/ dir and allow .htaccess rewrites
RUN a2enmod rewrite
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/conf-available/*.conf

# Opcache tuned for production
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini
RUN { \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# App code
COPY . .

# Vendor + built frontend assets from earlier stages
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

# Supervisor + entrypoint
COPY docker/supervisor/whatsmine.conf /etc/supervisor/conf.d/app-queues.conf
COPY docker/supervisor/app.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Laravel scheduler via cron (runs every minute, matches routes/console.php)
RUN echo "* * * * * www-data cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
