# ── PHP runtime ───────────────────────────────────────────────────────────────
# Frontend assets are pre-built on the host with `npm run prod` before docker build.
# For CI/prod builds use docker-compose.prod.yml which passes VITE_API_URL build-arg.
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

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/php/entrypoint.sh /entrypoint.sh
# Strip Windows CRLF line endings in case the file was edited on Windows
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
