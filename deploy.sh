#!/bin/bash
# Zero-downtime deploy script.
#
# Flow:
#   1. git pull
#   2. Build new image          ← old container still serves traffic
#   3. Run migrations (temp container) ← old container still serves traffic
#   4. Swap backend container   ← ~5s gap (FPM startup only, no migrations)
#   5. Restart queue + scheduler
set -eo pipefail

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}▶${NC} $1"; }
warn() { echo -e "${YELLOW}⚠${NC} $1"; }
die()  { echo -e "${RED}✖${NC} $1"; exit 1; }

# ── 1. Pull code ──────────────────────────────────────────────────────────────
log "Pulling latest code..."
git pull origin main --ff-only

# ── 2. Build frontend (only when JS/CSS/config changed) ───────────────────────
CHANGED=$(git diff HEAD@{1} --name-only 2>/dev/null || true)
if echo "$CHANGED" | grep -qE "resources/js|package\.json|vite\.config|tailwind\.config"; then
  log "Frontend files changed — building assets..."
  npm ci --prefer-offline
  npm run build
else
  log "No frontend changes — skipping npm build."
fi

# ── 3. Build new Docker image (old container stays up) ────────────────────────
log "Building new backend image..."
docker compose build backend

# ── 4. Run migrations using the NEW image (old backend still serving) ─────────
log "Running migrations with new image (old backend still active)..."
docker compose run --rm \
  -e SKIP_MIGRATIONS=0 \
  backend php artisan migrate --force

# ── 5. Swap backend container (gap ≈ FPM startup ~5s) ────────────────────────
log "Swapping backend container..."
SKIP_MIGRATIONS=1 docker compose up -d --no-deps --wait backend

log "New backend healthy. Warming caches..."
docker compose exec -T backend php artisan config:cache
docker compose exec -T backend php artisan route:cache
docker compose exec -T backend php artisan view:cache

# ── 6. Restart stateless workers (jobs auto-requeue on restart) ───────────────
log "Restarting queue workers and scheduler..."
docker compose up -d --no-deps queue scheduler

echo ""
echo -e "${GREEN}✅ Deploy complete.${NC}"
echo "   Old version ran until step 4. Gap was FPM startup only (~5s)."
