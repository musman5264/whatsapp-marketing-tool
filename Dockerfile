# syntax=docker/dockerfile:1

# =============================================================================
# Stage 1 — Frontend Build
# =============================================================================

FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.* ./
COPY jsconfig.json* tsconfig.json* ./

RUN npm run build


# =============================================================================
# Stage 2 — Composer Dependencies
# =============================================================================

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Copy directories referenced by Composer autoload configuration
COPY app ./app
COPY database ./database

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader


# =============================================================================
# Stage 3 — Laravel Runtime
# =============================================================================

FROM php:8.2-apache AS runtime

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive

# =============================================================================
# System Dependencies + PHP Extensions
# =============================================================================

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
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
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
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
    && apt-get purge -y --auto-remove \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        libcurl4-openssl-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*


# =============================================================================
# Apache Configuration
# =============================================================================

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN a2enmod rewrite

RUN sed -ri \
        -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
    && sed -ri \
        -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/conf-available/*.conf


# =============================================================================
# PHP Production Configuration
# =============================================================================

RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'max_execution_time=120'; \
        echo 'max_input_time=120'; \
    } > /usr/local/etc/php/conf.d/production.ini


# =============================================================================
# OPcache
# =============================================================================

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini


# =============================================================================
# Laravel Application
# =============================================================================

COPY . .

COPY --from=vendor /app/vendor ./vendor

COPY --from=frontend /app/public/build ./public/build


# =============================================================================
# Laravel Runtime Directories
# =============================================================================

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /var/log/supervisor \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
    && chmod -R 775 \
        storage \
        bootstrap/cache


# =============================================================================
# Laravel Scheduler
# =============================================================================

RUN echo "* * * * * www-data cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" \
        > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler


# =============================================================================
# Supervisor Configuration
# =============================================================================

COPY docker/supervisor/whatsmine.conf \
    /etc/supervisor/conf.d/app-queues.conf

COPY docker/supervisor/app.conf \
    /etc/supervisor/conf.d/app.conf


# =============================================================================
# Entrypoint
# =============================================================================

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh


# =============================================================================
# Container Configuration
# =============================================================================

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]

CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]