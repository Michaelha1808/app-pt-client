#!/bin/bash
# PM2 chạy script này để quản lý toàn bộ docker-compose stack.
# Khi PM2 restart, nó gửi SIGTERM → script dừng containers sạch → PM2 start lại.

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

cleanup() {
  echo "[pm2:caloeye] Stopping containers..."
  $COMPOSE down
  exit 0
}

trap cleanup SIGTERM SIGINT

echo "[pm2:caloeye] Starting containers..."
$COMPOSE up
