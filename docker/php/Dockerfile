# ── Stage 1: Build frontend ───────────────────────────────────────────────────
FROM node:20-alpine AS frontend-builder

ARG VITE_API_URL=/api/v1
# Firebase web config — phải có lúc BUILD vì Vite nhúng import.meta.env.VITE_* vào bundle.
# Thiếu các biến này thì FCM client không init được → không lấy được token → không nhận push.
# Đây là config PUBLIC của Firebase web (đằng nào cũng lộ trong JS client) nên hardcode default
# an toàn — build luôn có giá trị, không phụ thuộc GitHub Secrets.
ARG VITE_FIREBASE_API_KEY=AIzaSyCnA_jvjTgTH5HIOUacBe7d3vUA6ffficQ
ARG VITE_FIREBASE_AUTH_DOMAIN=app-pt-5464c.firebaseapp.com
ARG VITE_FIREBASE_PROJECT_ID=app-pt-5464c
ARG VITE_FIREBASE_STORAGE_BUCKET=app-pt-5464c.firebasestorage.app
ARG VITE_FIREBASE_MESSAGING_SENDER_ID=611776948824
ARG VITE_FIREBASE_APP_ID=1:611776948824:web:2e68dc59d2da623d52f82d
ARG VITE_FIREBASE_VAPID_KEY=BFH2Sw_J8o-u_cxndF-WBfu806cqtmrgKMfUTdzyHIIcYLAfFyM214_oubWkkzwiY8iXAzxRYHY37ZXP8dR2Bc4
ENV VITE_API_URL=${VITE_API_URL} \
    VITE_FIREBASE_API_KEY=${VITE_FIREBASE_API_KEY} \
    VITE_FIREBASE_AUTH_DOMAIN=${VITE_FIREBASE_AUTH_DOMAIN} \
    VITE_FIREBASE_PROJECT_ID=${VITE_FIREBASE_PROJECT_ID} \
    VITE_FIREBASE_STORAGE_BUCKET=${VITE_FIREBASE_STORAGE_BUCKET} \
    VITE_FIREBASE_MESSAGING_SENDER_ID=${VITE_FIREBASE_MESSAGING_SENDER_ID} \
    VITE_FIREBASE_APP_ID=${VITE_FIREBASE_APP_ID} \
    VITE_FIREBASE_VAPID_KEY=${VITE_FIREBASE_VAPID_KEY}

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

COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
