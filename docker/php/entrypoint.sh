#!/bin/sh
set -e

# ── Symlink storage (public/storage -> storage/app/public) ───────────────────
echo "[entrypoint] Linking storage..."
php artisan storage:link --force || true

# ── Đẩy public (kèm symlink storage) sang volume nginx phục vụ ───────────────
if [ -d /var/www/public-shared ]; then
  echo "[entrypoint] Copying public assets to shared volume..."
  cp -a /var/www/public/. /var/www/public-shared/
  # Đảm bảo symlink storage tồn tại trong volume nginx phục vụ
  ln -sfn /var/www/storage/app/public /var/www/public-shared/storage
fi

# Deploy script runs migrations in a temp container before swap.
# SKIP_MIGRATIONS=1 skips this step to minimise container-swap downtime.
if [ "${SKIP_MIGRATIONS:-0}" = "0" ]; then
  echo "[entrypoint] Running database migrations..."
  php artisan migrate --force
fi

# ── Xoá cache cũ trước khi cache lại (tránh config/route/view bị stale) ──────
echo "[entrypoint] Clearing stale caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled
php artisan cache:clear || true   # app cache (DB store) — bỏ qua nếu bảng chưa sẵn sàng

echo "[entrypoint] Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm
