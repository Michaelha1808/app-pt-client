# Hướng dẫn Deploy lên VPS

## Tổng quan quy trình

```
push lên main
  → GitHub Actions: build Docker image → push Docker Hub
  → GitHub Actions: SSH vào VPS
      → git pull (cập nhật config/nginx)
      → docker pull (image mới từ Docker Hub)
      → pm2 restart caloeye
          → PM2 gửi SIGTERM → containers dừng sạch
          → PM2 start lại với image mới
```

---

## Phần 1 — Setup VPS (làm một lần duy nhất)

### 1.1 Yêu cầu

- VPS Ubuntu 22.04 (tối thiểu 1 CPU, 1GB RAM)
- Tên miền đã trỏ A record về IP của VPS
- SSH vào VPS với quyền root hoặc sudo

### 1.2 Cài Docker

```bash
curl -fsSL https://get.docker.com | sh
apt install docker-compose-plugin -y
```

### 1.3 Cài Node.js và PM2

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install nodejs -y
npm install -g pm2
```

### 1.4 Tạo SSH deploy key

```bash
# Tạo key pair (không dùng passphrase)
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/deploy_key -N ""

# Cho phép key này SSH vào server
cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys

# In private key ra để copy vào GitHub Secret
cat ~/.ssh/deploy_key
```

### 1.5 Clone repo

```bash
git clone https://github.com/your-org/your-repo.git /var/www/app
cd /var/www/app
chmod +x scripts/start.sh
```

### 1.6 Tạo file `.env`

```bash
cp .env.example .env
nano .env
```

Điền đầy đủ (thay `yourdomain.com` và username Docker Hub):

```env
APP_NAME="CaloEye"
APP_ENV=production
APP_DEBUG=false
APP_KEY=             # xem cách tạo ở dưới
APP_URL=https://yourdomain.com
DOMAIN=yourdomain.com

POSTGRES_DB=pt_client
POSTGRES_USER=pt_user
POSTGRES_PASSWORD=mat_khau_manh_o_day

SANCTUM_STATEFUL_DOMAINS=yourdomain.com
VITE_API_URL=https://yourdomain.com/api/v1

# Docker Hub image (build bởi GitHub Actions)
DOCKER_IMAGE=dockerhub_username/caloeye-backend:latest
```

**Tạo `APP_KEY`:**

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

### 1.7 Login Docker Hub trên VPS

```bash
docker login -u YOUR_DOCKERHUB_USERNAME
# Nhập password hoặc access token khi hỏi
```

> Credentials được lưu tại `~/.docker/config.json`, dùng lại cho mọi lần `docker pull` sau.

### 1.8 Mở firewall

```bash
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw enable
```

### 1.9 Lấy SSL certificate

> Chạy **trước** khi start app (dùng standalone, tự bind cổng 80).

```bash
apt install certbot -y

certbot certonly --standalone \
  -d yourdomain.com \
  --email your@email.com \
  --agree-tos \
  --no-eff-email
```

### 1.10 Tạo thư mục certbot webroot

```bash
mkdir -p /var/www/app/certbot/www
```

### 1.11 Pull image lần đầu và start PM2

```bash
cd /var/www/app

# Pull image từ Docker Hub (phải push image lên Docker Hub trước)
docker pull dockerhub_username/caloeye-backend:latest

# Start toàn bộ stack qua PM2
pm2 start ecosystem.config.js

# Đăng ký PM2 tự start khi VPS reboot
pm2 save
pm2 startup
# → Copy lệnh mà PM2 in ra và chạy nó
```

**Kiểm tra:**

```bash
pm2 status
pm2 logs caloeye
```

Mở `https://yourdomain.com` — app đã chạy.

---

## Phần 2 — Cài đặt GitHub Secrets (làm một lần)

Vào **GitHub repo → Settings → Secrets and variables → Actions**, thêm:

| Secret | Giá trị |
|--------|---------|
| `DOCKERHUB_USERNAME` | Username Docker Hub |
| `DOCKERHUB_TOKEN` | Access token Docker Hub (tạo tại hub.docker.com → Account Settings → Security) |
| `VITE_API_URL` | `https://yourdomain.com/api/v1` |
| `VPS_HOST` | IP hoặc domain của VPS |
| `VPS_USER` | User SSH (`root` hoặc `ubuntu`) |
| `VPS_SSH_KEY` | Nội dung file `~/.ssh/deploy_key` (private key từ bước 1.4) |

---

## Phần 3 — Quy trình deploy hàng ngày

Sau khi setup xong, chỉ cần:

```bash
git checkout main
git merge develop        # hoặc merge PR trên GitHub
git push origin main
```

GitHub Actions tự động:
1. Build Docker image mới (với `VITE_API_URL` baked in)
2. Push lên Docker Hub
3. SSH vào VPS: pull image → `pm2 restart caloeye`

Theo dõi tại: **GitHub repo → Actions**

---

## Gia hạn SSL tự động

```bash
crontab -e
```

```
0 3 1 * * certbot renew --webroot -w /var/www/app/certbot/www --quiet && docker compose -f /var/www/app/docker-compose.prod.yml exec -T nginx nginx -s reload
```

---

## Một số lệnh hữu ích

```bash
# Xem trạng thái PM2
pm2 status
pm2 logs caloeye --lines 100

# Xem log từng container
docker compose -f /var/www/app/docker-compose.prod.yml logs -f backend

# Vào shell backend
docker compose -f /var/www/app/docker-compose.prod.yml exec backend sh

# Chạy artisan thủ công
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan migrate --force
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan cache:clear

# Restart thủ công (khi cần, không cần push code)
docker pull dockerhub_username/caloeye-backend:latest
pm2 restart caloeye

# Dọn image cũ
docker image prune -f

# Dừng toàn bộ app
pm2 stop caloeye

# Xóa toàn bộ data (cẩn thận!)
pm2 stop caloeye
docker compose -f /var/www/app/docker-compose.prod.yml down -v
```
