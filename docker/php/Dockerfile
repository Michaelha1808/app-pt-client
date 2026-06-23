# ── Stage 1: Build frontend ───────────────────────────────────────────────────
FROM node:20-alpine AS frontend-builder

# VITE_API_URL is passed as build-arg from docker-compose.prod.yml
ARG VITE_API_URL
ENV VITE_API_URL=${VITE_API_URL}

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run prod


# ── Stage 2: PHP runtime ──────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    oniguruma-dev

RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    zip \
    exif \
    pcntl \
    bcmath \
    gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .
COPY --from=frontend-builder /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/php/entrypoint.sh /entrypoint.sh
# Strip Windows CRLF line endings in case the file was edited on Windows
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
