#!/bin/sh
set -e

echo "[entrypoint] Copying public assets to shared volume..."
cp -r /var/www/public/. /var/www/public-shared/

echo "[entrypoint] Running database migrations..."
php artisan migrate --force

echo "[entrypoint] Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm
