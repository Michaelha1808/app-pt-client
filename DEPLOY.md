# CI/CD Deployment Flow — Laravel + Vue 3 SPA (GitHub Actions + Docker Hub + PM2 + VPS)

> Tài liệu này mô tả luồng deploy tự động cho dự án CaloEye (Laravel 13 backend + Vue 3 SPA frontend + PostgreSQL). Mục đích: giúp đọc source code mà hiểu ngay kiến trúc CI/CD mà không cần đọc lại toàn bộ workflow.

---

## 1. Tổng quan kiến trúc

```
Developer push/merge → nhánh "main"
        │
        ▼
GitHub Actions (CI/CD pipeline)
        │
        ├─► Build Docker image (frontend Vue 3 đã baked vào, VITE_API_URL từ secret)
        ├─► Push image lên Docker Hub
        │
        ▼
SSH vào VPS
        │
        ├─► git pull (cập nhật docker-compose, nginx config)
        ├─► docker pull image mới từ Docker Hub
        ├─► pm2 restart caloeye
        │       └─► PM2 gửi SIGTERM → docker compose down → docker compose up
        │
        ▼
3 tiến trình chạy production trên VPS:
        ├─► nginx      (serve Vue 3 SPA + proxy PHP)
        ├─► php-fpm    (Laravel API backend)
        └─► postgres   (database)
```

---

## 2. Các thành phần chính

| Thành phần | Vai trò |
|---|---|
| **GitHub Actions** | Tự động trigger pipeline khi có commit/merge vào nhánh `main` |
| **Docker Hub** | Lưu trữ Docker image đã build (registry trung gian giữa CI và VPS) |
| **Docker Image** | Đóng gói toàn bộ Laravel + Vue 3 build + dependencies thành 1 image |
| **docker-compose.prod.yml** | Định nghĩa 3 service: `backend` (php-fpm), `nginx`, `postgres` |
| **PM2** | Cài trên **host VPS**, quản lý toàn bộ docker-compose stack (tự restart nếu crash, log tập trung) |
| **VPS** | Server thực tế chạy các container production |
| **GitHub Secrets** | Lưu trữ thông tin nhạy cảm: tài khoản Docker Hub, SSH key VPS, VITE_API_URL |

> **Khác với Node.js:** PM2 ở đây chạy **trên host VPS** (không phải bên trong container) vì project có 3 container cần quản lý cùng lúc. PM2 quản lý process `scripts/start.sh` — script này khởi động toàn bộ `docker compose up`.

---

## 3. Luồng chi tiết (step-by-step)

### Bước 1 — Trigger
Khi có `push` vào nhánh `main`, GitHub Actions tự động kích hoạt workflow (`.github/workflows/deploy.yml`).

### Bước 2 — Build Docker image
- Job `build` checkout code.
- `VITE_API_URL` (URL API production) được inject vào image qua `--build-arg` lấy từ GitHub Secrets. Giá trị này được Vite **bake cố định vào JS bundle** lúc build — không thay đổi được lúc runtime.
- Build image từ `docker/php/Dockerfile` (multi-stage: Node build Vue 3 → PHP-FPM image).
- Tận dụng GitHub Actions cache (`cache-from: type=gha`) để tái sử dụng layer cũ, tăng tốc build.

### Bước 3 — Push lên Docker Hub
- Đăng nhập Docker Hub bằng `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN` (từ GitHub Secrets).
- Tag image với **2 tag**: `:latest` và `:${{ github.sha }}` (commit hash).
  - `:latest` dùng cho VPS pull tự động.
  - `:commit-sha` dùng để rollback về commit cụ thể nếu cần.
- Push lên Docker Hub repository.

### Bước 4 — Deploy lên VPS
Job `deploy` (`needs: build`) chỉ chạy sau khi job `build` thành công.

GitHub Actions SSH vào VPS bằng **SSH key** (`VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`). Trên VPS thực hiện tuần tự:

1. `git pull origin main` — cập nhật `docker-compose.prod.yml`, `docker/nginx/prod.conf`, `ecosystem.config.js` từ repo (các file config không nằm trong image).
2. `docker pull image:latest` — kéo image mới nhất từ Docker Hub về VPS.
3. `pm2 restart caloeye` — PM2 gửi SIGTERM tới `scripts/start.sh`:
   - Script nhận SIGTERM → chạy `docker compose down` (dừng sạch 3 container).
   - PM2 start lại `scripts/start.sh` → chạy `docker compose up` với image mới.
4. `docker image prune -f` — xóa image cũ không dùng, giải phóng ổ đĩa.

### Bước 5 — Hoàn tất
3 container chạy với image mới: **nginx** (port 80/443) → proxy → **php-fpm** → **postgres**. Toàn bộ tự động từ lúc merge code.

---

## 4. GitHub Secrets cần khai báo

Khai báo tại: **Repo → Settings → Secrets and variables → Actions → Secrets**

| Secret | Mục đích |
|---|---|
| `DOCKERHUB_USERNAME` | Username đăng nhập Docker Hub |
| `DOCKERHUB_TOKEN` | Access token Docker Hub (tạo tại hub.docker.com → Account Settings → Security) |
| `VITE_API_URL` | URL API production, VD: `https://yourdomain.com/api/v1` — baked vào JS bundle lúc build |
| `VPS_HOST` | Địa chỉ IP hoặc domain của VPS |
| `VPS_USER` | Username SSH vào VPS (`root` hoặc `ubuntu`) |
| `VPS_SSH_KEY` | Nội dung private key SSH (tạo riêng cho deploy, không dùng key cá nhân) |

> ⚠️ Không bao giờ hard-code các giá trị này trong code hay commit vào repo. Toàn bộ đều inject qua `${{ secrets.XXX }}` lúc runtime.

> **Lưu ý về `.env` Laravel:** Khác với Node.js (bake `.env` vào image), Laravel đọc `.env` lúc **runtime** từ file trên VPS. File này nằm tại `/var/www/app/.env` trên VPS, **không** đi qua GitHub Secrets hay bị đưa vào image. Các biến nhạy cảm (APP_KEY, DB_PASSWORD) chỉ tồn tại trên VPS.

---

## 5. Workflow thực tế: `.github/workflows/deploy.yml`

```yaml
name: Deploy

on:
  push:
    branches:
      - main

env:
  IMAGE: ${{ secrets.DOCKERHUB_USERNAME }}/caloeye-backend

jobs:
  build:
    name: Build & Push Docker Image
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Login to Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          file: docker/php/Dockerfile
          push: true
          tags: |
            ${{ env.IMAGE }}:latest
            ${{ env.IMAGE }}:${{ github.sha }}
          build-args: |
            VITE_API_URL=${{ secrets.VITE_API_URL }}
          cache-from: type=gha
          cache-to: type=gha,mode=max

  deploy:
    name: Deploy to VPS
    runs-on: ubuntu-latest
    needs: build
    steps:
      - name: SSH & deploy
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.VPS_HOST }}
          username: ${{ secrets.VPS_USER }}
          key: ${{ secrets.VPS_SSH_KEY }}
          script: |
            set -e
            cd /var/www/app
            git pull origin main
            docker pull ${{ env.IMAGE }}:latest
            pm2 restart caloeye
            docker image prune -f
            echo "✓ Deploy complete — $(date)"
```

### Phân tích workflow

**Job `build`** (chạy trên `ubuntu-latest`):
| Bước | Ý nghĩa |
|---|---|
| `actions/checkout@v4` | Checkout toàn bộ source code vào runner |
| `docker/login-action@v3` | Đăng nhập Docker Hub an toàn (không log password ra stdout như `docker login -p`) |
| `docker/setup-buildx-action@v3` | Kích hoạt BuildKit — hỗ trợ multi-platform và build cache |
| `docker/build-push-action@v5` | Build multi-stage image, inject `VITE_API_URL` qua `build-args`, push 2 tag lên Docker Hub |
| `cache-from/cache-to: type=gha` | Cache Docker layer trên GitHub Actions — lần build sau nếu `package.json` và `composer.json` không đổi thì skip được `npm ci` và `composer install` |

**Job `deploy`** (`needs: build` → chỉ chạy sau build xong):
| Bước | Ý nghĩa |
|---|---|
| `git pull origin main` | Cập nhật config files không nằm trong image (`docker-compose.prod.yml`, nginx config) |
| `docker pull image:latest` | Kéo image mới về VPS (image đã build và push ở job trước) |
| `pm2 restart caloeye` | PM2 graceful restart toàn bộ stack (xem mục 7) |
| `docker image prune -f` | Xóa image cũ không còn tag, giải phóng ổ đĩa |

---

## 6. Dockerfile thực tế: `docker/php/Dockerfile`

```dockerfile
# ── Stage 1: Build frontend ────────────────────────────────────────────────
FROM node:20-alpine AS frontend-builder

ARG VITE_API_URL
ENV VITE_API_URL=${VITE_API_URL}

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run prod        # Vite build → public/build/ (đọc VITE_API_URL từ env)

# ── Stage 2: PHP runtime ───────────────────────────────────────────────────
FROM php:8.4-fpm-alpine

RUN apk add --no-cache git curl libpng-dev libzip-dev zip unzip postgresql-dev oniguruma-dev
RUN docker-php-ext-install pdo pdo_pgsql pgsql mbstring zip exif pcntl bcmath gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .
COPY --from=frontend-builder /app/public/build ./public/build   # Vue 3 build baked vào đây

RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
```

### Phân tích Dockerfile

| Dòng | Ý nghĩa |
|---|---|
| `FROM node:20-alpine AS frontend-builder` | Stage 1: dùng Node để build Vue 3 SPA |
| `ARG VITE_API_URL` / `ENV VITE_API_URL` | Nhận build-arg từ GitHub Actions, expose thành env var cho Vite đọc |
| `RUN npm ci` | Install dependencies từ `package-lock.json` (deterministic, nhanh hơn `npm install`) |
| `RUN npm run prod` | Vite build production — **đọc `VITE_API_URL` từ env, bake cứng vào JS bundle** |
| `FROM php:8.4-fpm-alpine` | Stage 2: PHP-FPM runtime (Alpine = nhẹ) |
| `COPY --from=frontend-builder /app/public/build ./public/build` | Copy output Vue 3 từ stage 1 vào image PHP — đây là cách "nhúng" frontend vào backend image |
| `composer install --no-dev` | Cài PHP dependencies, bỏ qua dev packages |
| `ENTRYPOINT ["/entrypoint.sh"]` | Script chạy mỗi lần container start (migrations, cache, copy public files) |

> 💡 Chuỗi liên kết: **GitHub Secret `VITE_API_URL`** → `build-arg` → `ARG VITE_API_URL` trong Dockerfile → `ENV VITE_API_URL` → `npm run prod` đọc và bake vào JS → file JS trong `public/build/` chứa URL cố định. **Sau khi build xong, không thể đổi URL này mà không rebuild image.**

---

## 7. `ecosystem.config.js` và `scripts/start.sh` — PM2 quản lý stack

### `ecosystem.config.js`

```js
module.exports = {
  apps: [
    {
      name: 'caloeye',
      script: '/var/www/app/scripts/start.sh',
      interpreter: 'bash',
      autorestart: true,
      watch: false,
      kill_timeout: 30000,   // 30s cho containers shutdown sạch trước khi PM2 force-kill
    },
  ],
}
```

### `scripts/start.sh`

```bash
#!/bin/bash
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

cleanup() {
  echo "[pm2:caloeye] Stopping containers..."
  $COMPOSE down
  exit 0
}

trap cleanup SIGTERM SIGINT

echo "[pm2:caloeye] Starting containers..."
$COMPOSE up        # foreground — PM2 giám sát process này
```

### Phân tích

| Key | Ý nghĩa |
|---|---|
| `name: 'caloeye'` | Tên process trong `pm2 list` / `pm2 logs caloeye` |
| `script: 'scripts/start.sh'` | PM2 chạy bash script này, script chạy `docker compose up` ở **foreground** |
| `autorestart: true` | Nếu `docker compose up` exit (crash/lỗi), PM2 tự start lại |
| `kill_timeout: 30000` | Khi `pm2 restart`, PM2 đợi tối đa 30s cho script dọn dẹp (chạy `docker compose down`) trước khi force-kill |
| `trap cleanup SIGTERM` | Khi PM2 gửi SIGTERM (lúc restart/stop), script bắt được và chạy `docker compose down` gracefully |

### Chuỗi restart khi deploy

```
pm2 restart caloeye
        │
        ▼
PM2 gửi SIGTERM → scripts/start.sh
        │
        ▼
trap SIGTERM → docker compose down
        │   (dừng nginx + php-fpm + postgres sạch)
        ▼
PM2 start lại scripts/start.sh
        │
        ▼
docker compose up (với image mới đã pull)
        │
        ├─► postgres   khởi động, healthcheck pass
        ├─► backend    start → entrypoint.sh chạy migrate + cache
        └─► nginx      khởi động sau khi backend healthy
```

---

## 8. `docker-compose.prod.yml` — định nghĩa 3 tiến trình

```yaml
services:
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}"]
      interval: 10s
      retries: 5

  backend:
    image: ${DOCKER_IMAGE}            # image từ Docker Hub, build bởi GitHub Actions
    restart: unless-stopped
    volumes:
      - app_storage:/var/www/storage          # logs, sessions, uploads — persist qua deploy
      - app_public:/var/www/public-shared     # entrypoint copy public/ vào đây cho nginx đọc
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_HOST=postgres
      ...
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "test -f /var/www/public-shared/index.php"]

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - app_public:/var/www/public:ro             # static Vue 3 files từ backend image
      - /etc/letsencrypt:/etc/letsencrypt:ro      # SSL cert
    depends_on:
      backend:
        condition: service_healthy

volumes:
  postgres_data:    # data DB persist
  app_storage:      # Laravel storage persist
  app_public:       # Vue 3 build files (shared backend → nginx)
```

### Cơ chế chia sẻ frontend files giữa backend và nginx

```
backend container (image từ Docker Hub)
    │   entrypoint.sh: cp -r /var/www/public/. /var/www/public-shared/
    │
    ▼ volume: app_public
        │
        ▼
nginx container mount app_public:/var/www/public:ro
    → serve CSS/JS/images trực tiếp (không qua PHP-FPM)
    → request API → proxy đến backend:9000 (PHP-FPM)
```

---

## 9. Setup VPS lần đầu (làm một lần duy nhất)

### 9.1 Cài Docker + Node + PM2

```bash
# Docker
curl -fsSL https://get.docker.com | sh
apt install docker-compose-plugin -y

# Node.js + PM2
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install nodejs -y
npm install -g pm2
```

### 9.2 Tạo SSH deploy key

```bash
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/deploy_key -N ""
cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys

# Copy private key này vào GitHub Secret VPS_SSH_KEY
cat ~/.ssh/deploy_key
```

### 9.3 Clone repo và cấu hình

```bash
git clone https://github.com/your-org/your-repo.git /var/www/app
cd /var/www/app
chmod +x scripts/start.sh

# Tạo .env từ template
cp .env.example .env
nano .env   # điền APP_KEY, domain, DB password, DOCKER_IMAGE...

# Tạo APP_KEY
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

### 9.4 Mở firewall, lấy SSL cert

```bash
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable

apt install certbot -y
certbot certonly --standalone -d yourdomain.com --email your@email.com --agree-tos --no-eff-email

mkdir -p /var/www/app/certbot/www
```

### 9.5 Login Docker Hub và start PM2

```bash
docker login -u YOUR_DOCKERHUB_USERNAME
# Nhập token khi hỏi — credentials lưu lại, dùng cho các lần pull sau

# Pull image lần đầu
docker pull dockerhub_username/caloeye-backend:latest

# Start toàn bộ stack qua PM2
pm2 start /var/www/app/ecosystem.config.js
pm2 save
pm2 startup   # copy lệnh mà PM2 in ra và chạy nó
```

---

## 10. Checklist setup lần đầu

- [ ] Tạo Docker Hub repository (`dockerhub_username/caloeye-backend`)
- [ ] Khai báo 6 GitHub Secrets: `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN`, `VITE_API_URL`, `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`
- [ ] VPS: cài Docker, Node.js, PM2
- [ ] VPS: tạo SSH deploy key, thêm public key vào `authorized_keys`
- [ ] VPS: clone repo, tạo `.env`, mở firewall, lấy SSL cert
- [ ] VPS: `docker login`, `docker pull`, `pm2 start`, `pm2 save`, `pm2 startup`
- [ ] Test push lên `main` → theo dõi **GitHub repo → Actions**
- [ ] Kiểm tra `pm2 status` và `pm2 logs caloeye` trên VPS
- [ ] Mở `https://yourdomain.com` — app chạy với HTTPS

---

## 11. Các lệnh hữu ích trên VPS

```bash
# Trạng thái PM2
pm2 status
pm2 logs caloeye --lines 100

# Log từng container
docker compose -f /var/www/app/docker-compose.prod.yml logs -f backend
docker compose -f /var/www/app/docker-compose.prod.yml logs -f nginx

# Vào shell backend
docker compose -f /var/www/app/docker-compose.prod.yml exec backend sh

# Chạy artisan thủ công
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan migrate --force
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan cache:clear

# Rollback về commit cụ thể
docker pull dockerhub_username/caloeye-backend:COMMIT_SHA
# Sửa DOCKER_IMAGE trong .env → pm2 restart caloeye

# Dọn image cũ
docker image prune -f

# Restart thủ công (không cần push code)
pm2 restart caloeye

# Dừng toàn bộ
pm2 stop caloeye

# Gia hạn SSL (cron chạy tự động)
certbot renew --webroot -w /var/www/app/certbot/www --quiet \
  && docker compose -f /var/www/app/docker-compose.prod.yml exec -T nginx nginx -s reload
```

---

*File này dùng làm tài liệu nội bộ để hiểu nhanh kiến trúc CI/CD của dự án khi đọc source code.*
