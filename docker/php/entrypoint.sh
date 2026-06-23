#!/bin/sh
set -e

if [ -d /var/www/public-shared ]; then
  echo "[entrypoint] Copying public assets..."
  cp -r /var/www/public/. /var/www/public-shared/
fi

# Deploy script runs migrations in a temp container before swap.
# SKIP_MIGRATIONS=1 skips this step to minimise container-swap downtime.
if [ "${SKIP_MIGRATIONS:-0}" = "0" ]; then
  echo "[entrypoint] Running database migrations..."
  php artisan migrate --force
fi

echo "[entrypoint] Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm
