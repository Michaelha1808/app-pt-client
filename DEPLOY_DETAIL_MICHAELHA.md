# Hướng dẫn Deploy Chi Tiết — PT Client (Copy-paste Ready)

> Làm tuần tự từ trên xuống. Mỗi bước có lệnh copy-paste thẳng vào terminal.  
> **Phần A + B + C** làm **1 lần duy nhất** khi setup lần đầu.  
> **Phần D** là quy trình mỗi lần release về sau — chỉ cần `git push`.

---

## PHẦN A — CHUẨN BỊ TÀI KHOẢN & REPO (trên trình duyệt, không phải VPS)

### A1 — Tạo Docker Hub repository

1. Đăng nhập [hub.docker.com](https://hub.docker.com)
2. Click **Create repository**
3. Repository name: `caloeye-backend`
4. Visibility: **Public** (hoặc Private nếu muốn, cần login trên VPS)
5. Click **Create**

Ghi nhớ tên đầy đủ: `<dockerhub_username>/caloeye-backend`

---

### A2 — Tạo Docker Hub Access Token

1. Docker Hub → góc phải trên → **Account Settings**
2. **Security** → **New Access Token**
3. Description: `github-actions`
4. Permissions: **Read, Write, Delete**
5. Click **Generate** → **sao chép token ngay** (chỉ hiển thị 1 lần)

Lưu token này lại — sẽ dùng ở bước B3 (GitHub Secret) và C9 (login Docker Hub trên VPS).

---

### A3 — Tạo GitHub repository (nếu chưa có)

Repo đã tồn tại tại: `https://github.com/Michaelha1808/app-pt-client`

Đảm bảo code đã được push lên nhánh `main`.

---

## PHẦN B — KHAI BÁO GITHUB SECRETS (trên trình duyệt)

Vào: **GitHub repo → Settings → Secrets and variables → Actions → New repository secret**

Khai báo lần lượt 6 secret sau (bấm **New repository secret** cho từng cái):

| Secret | Giá trị cần điền |
|---|---|
| `DOCKERHUB_USERNAME` | Username Docker Hub (VD: `michaelha1808`) |
| `DOCKERHUB_TOKEN` | Access Token vừa tạo ở A2 |
| `VITE_API_URL` | `https://<yourdomain.com>/api/v1` — URL API production |
| `VPS_HOST` | IP hoặc domain của VPS (VD: `103.x.x.x`) |
| `VPS_USER` | Username SSH vào VPS, thường là `root` hoặc `ubuntu` |
| `VPS_SSH_KEY` | Nội dung private key SSH — **xem hướng dẫn tạo ở C1** |

> `VPS_SSH_KEY` cần tạo trên VPS trước (bước C1), nên có thể quay lại điền sau.

---

## PHẦN C — SETUP VPS LẦN ĐẦU (SSH vào VPS, chạy tuần tự)

> Tất cả lệnh dưới đây chạy với quyền **root** trên VPS Ubuntu/Debian.

---

### C1 — Tạo SSH deploy key (để GitHub Actions SSH vào VPS được)

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/deploy_key -N ""
cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

In ra **private key** để copy vào GitHub Secret `VPS_SSH_KEY`:

```bash
cat ~/.ssh/deploy_key
```

Sao chép **toàn bộ** output từ dòng `-----BEGIN OPENSSH PRIVATE KEY-----` đến dòng `-----END OPENSSH PRIVATE KEY-----` (bao gồm cả 2 dòng đó).

Quay lại Phần B, tạo secret `VPS_SSH_KEY` với nội dung vừa copy.

---

### C2 — Cài Docker

```bash
curl -fsSL https://get.docker.com | sh
apt install docker-compose-plugin -y
```

Kiểm tra:

```bash
docker --version
docker compose version
```

Kết quả mong đợi: in ra version (không báo lỗi).

---

### C3 — Cài Node.js và PM2

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install nodejs -y
npm install -g pm2
```

Kiểm tra:

```bash
node --version
pm2 --version
```

Kết quả mong đợi: in ra `v20.x.x` và `5.x.x`.

---

### C4 — Clone repo về VPS

**Tại sao phải clone?**  
Docker image chỉ chứa code PHP + Vue build. Các file cấu hình sau **không nằm trong image** mà phải có trên host VPS để Docker Compose đọc được:
- `docker-compose.prod.yml` — định nghĩa 5 service (postgres, backend, scheduler, queue, nginx)
- `docker/nginx/prod.conf` — cấu hình nginx
- `docker/php/php.ini` — cấu hình PHP
- `docker/postgres/init.sql` — script khởi tạo DB
- `ecosystem.config.js` — cấu hình PM2
- `scripts/start.sh` — script PM2 chạy để khởi động toàn bộ stack

Mỗi lần deploy, GitHub Actions chạy `git pull` trên VPS để cập nhật các file config này mà không cần rebuild image.

```bash
mkdir -p /var/www
git clone https://github.com/Michaelha1808/app-pt-client.git /var/www/app
chmod +x /var/www/app/scripts/start.sh
```

Kiểm tra:

```bash
ls /var/www/app
```

Kết quả mong đợi: thấy `docker-compose.prod.yml`, `ecosystem.config.js`, `scripts/`, `docker/`, v.v.

---

### C5 — Mở firewall

```bash
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw --force enable
ufw status
```

Kết quả mong đợi: Status `active`, thấy rule cho port 22, 80, 443.

---

### C5b — Trỏ tên miền caloeye.xyz về VPS (Mắt Bão)

> Bước này làm trên **trình duyệt**, không phải terminal VPS.

**1. Lấy IP của VPS** (chạy trên terminal VPS):

```bash
curl -4 ifconfig.me
```

Ghi nhớ địa chỉ IP này (VD: `103.x.x.x`).

**2. Đăng nhập Mắt Bão và vào quản lý DNS:**

1. Truy cập [matbao.net](https://matbao.net) → Đăng nhập
2. Góc phải trên → **Quản lý dịch vụ**
3. Chọn mục **Tên miền** → tìm `caloeye.xyz` → bấm **Quản lý**
4. Chọn tab **Quản lý DNS** (hoặc **Thay đổi DNS Record**)

**3. Thêm 2 A record sau** (xóa các A record cũ nếu có trỏ lung tung):

| Type | Host | Value | TTL |
|---|---|---|---|
| A | `@` | `<IP VPS>` | 3600 |
| A | `www` | `<IP VPS>` | 3600 |

- **Type:** A
- **Host:** `@` nghĩa là domain gốc (`caloeye.xyz`), `www` nghĩa là `www.caloeye.xyz`
- **Value:** IP VPS vừa lấy ở bước 1
- Bấm **Lưu / Cập nhật**

**4. Chờ DNS propagate và kiểm tra:**

DNS thường mất 5–30 phút để có hiệu lực (tối đa 24h). Kiểm tra bằng lệnh sau trên terminal bất kỳ:

```bash
nslookup caloeye.xyz
```

Kết quả mong đợi: thấy IP VPS của bạn trong phần `Address`.

Hoặc kiểm tra online tại: [dnschecker.org](https://dnschecker.org) → nhập `caloeye.xyz`

> **Không được chạy bước C6 (lấy SSL) khi DNS chưa propagate** — certbot sẽ báo lỗi vì không xác minh được domain.

---

### C6 — Lấy SSL certificate (Let's Encrypt)

> **Yêu cầu trước:** Domain đã được trỏ A record về IP của VPS (kiểm tra bằng `nslookup caloeye.xyz` thấy đúng IP VPS).

```bash
apt install certbot -y
certbot certonly --standalone -d caloeye.xyz -d www.caloeye.xyz --email <your@email.com> --agree-tos --no-eff-email
```

Thay `<your@email.com>` bằng email thật của bạn.

Kiểm tra cert đã có:

```bash
ls /etc/letsencrypt/live/caloeye.xyz/
```

Kết quả mong đợi: thấy `fullchain.pem` và `privkey.pem`.

Tạo thư mục webroot cho certbot (dùng khi tự động gia hạn sau này):

```bash
mkdir -p /var/www/app/certbot/www
```

Thêm cron tự động gia hạn SSL (chạy 1 lần):

```bash
(crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet && docker compose -f /var/www/app/docker-compose.prod.yml exec -T nginx nginx -s reload") | crontab -
```

---

### C7 — Tạo file `.env` production trên VPS

```bash
cp /var/www/app/.env.example /var/www/app/.env
nano /var/www/app/.env
```

Điền các giá trị sau (thay `<...>` bằng giá trị thật của bạn):

```env
# ── Application ──────────────────────────────────────────────────────────────
APP_NAME="PT Client"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<yourdomain.com>

# Điền APP_KEY sau khi chạy lệnh ở bước C8
APP_KEY=base64:<...>

# ── Database ─────────────────────────────────────────────────────────────────
POSTGRES_DB=pt_client
POSTGRES_USER=pt_user
POSTGRES_PASSWORD=<mật_khẩu_mạnh_ví_dụ_Abc@123456>

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=pt_client
DB_USERNAME=pt_user
DB_PASSWORD=<mật_khẩu_giống_POSTGRES_PASSWORD_ở_trên>

# ── Session / Sanctum ─────────────────────────────────────────────────────────
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=<yourdomain.com>
FRONTEND_URL=https://<yourdomain.com>
SANCTUM_STATEFUL_DOMAINS=<yourdomain.com>

# ── Docker / Nginx ────────────────────────────────────────────────────────────
# Tên image trên Docker Hub — docker-compose.prod.yml đọc biến này
DOCKER_IMAGE=<dockerhub_username>/caloeye-backend:latest
DOMAIN=<yourdomain.com>

# ── Queue / Cache ─────────────────────────────────────────────────────────────
QUEUE_CONNECTION=database
CACHE_STORE=database

# ── Mail ─────────────────────────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=<smtp_host>
MAIL_PORT=587
MAIL_USERNAME=<email>
MAIL_PASSWORD=<password>
MAIL_FROM_ADDRESS=<email>
MAIL_FROM_NAME="PT Client"

# ── Gemini AI ─────────────────────────────────────────────────────────────────
GEMINI_API_KEY=<api_key — lấy miễn phí tại aistudio.google.com>
GEMINI_MODEL=gemini-2.0-flash

# ── Firebase Push Notifications ───────────────────────────────────────────────
# Lấy từ: Firebase Console → Project Settings → Service accounts → Generate new private key
# Mở file JSON tải về → copy toàn bộ nội dung → minify thành 1 dòng → paste vào đây
FIREBASE_CREDENTIALS={"type":"service_account","project_id":"..."}
```

Lưu file: `Ctrl+O` → `Enter` → `Ctrl+X`.

---

### C8 — Sinh APP_KEY cho Laravel

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Sao chép output (dạng `base64:xxxxxx...`), rồi điền vào `APP_KEY=` trong file `.env`:

```bash
nano /var/www/app/.env
# Tìm dòng APP_KEY= và điền vào
```

---

### C9 — Đăng nhập Docker Hub trên VPS

```bash
docker login -u <dockerhub_username>
```

Khi được hỏi password: nhập **Access Token** đã tạo ở bước A2 (không phải mật khẩu Docker Hub).

> Credentials được lưu lại trên VPS — các lần `docker pull` về sau không cần login lại.

---

### C10 — Build và push image lần đầu lên Docker Hub

> Cần làm bước này để có image trước khi start PM2. Có 2 cách:

**Cách 1 — Trigger GitHub Actions (khuyến nghị):**

```bash
# Trên máy local
git push origin main
```

Vào **GitHub repo → Actions** theo dõi job `Build & Push Docker Image` chạy xong (dấu tích xanh) rồi mới tiếp tục.

**Cách 2 — Build thủ công trên máy local rồi push:**

```bash
# Trên máy local
docker build \
  --build-arg VITE_API_URL=https://<yourdomain.com>/api/v1 \
  -f docker/php/Dockerfile \
  -t <dockerhub_username>/caloeye-backend:latest .

docker push <dockerhub_username>/caloeye-backend:latest
```

---

### C11 — Pull image về VPS

```bash
docker pull <dockerhub_username>/caloeye-backend:latest
```

Kết quả mong đợi: thấy `Pull complete` cho các layer, cuối cùng là `Status: Downloaded newer image`.

---

### C12 — Start toàn bộ stack qua PM2

```bash
pm2 start /var/www/app/ecosystem.config.js
```

Kết quả mong đợi: thấy bảng process với `caloeye` ở status `online`.

Lưu danh sách process để PM2 tự start lại khi VPS reboot:

```bash
pm2 save
```

Thiết lập PM2 tự start khi VPS khởi động lại:

```bash
pm2 startup
```

Lệnh trên sẽ in ra 1 lệnh bắt đầu bằng `sudo env PATH=...` — **copy lệnh đó và chạy ngay**. Ví dụ:

```bash
# Đây là ví dụ — chạy lệnh PM2 in ra, không dùng dòng này
sudo env PATH=$PATH:/usr/bin /usr/lib/node_modules/pm2/bin/pm2 startup systemd -u root --hp /root
```

---

### C13 — Kiểm tra toàn bộ stack

Kiểm tra PM2:

```bash
pm2 status
```

Kết quả mong đợi: `caloeye` ở status `online`.

Kiểm tra log khởi động:

```bash
pm2 logs caloeye --lines 80
```

Kết quả mong đợi: thấy log `[pm2:caloeye] Starting containers...` và các container khởi động lần lượt.

Kiểm tra 5 container:

```bash
docker compose -f /var/www/app/docker-compose.prod.yml ps
```

Kết quả mong đợi: cả 5 service (`postgres`, `backend`, `scheduler`, `queue`, `nginx`) đều `running` / `healthy`.

Mở browser → `https://<yourdomain.com>` — app chạy với HTTPS.

---

## PHẦN D — QUY TRÌNH DEPLOY TỰ ĐỘNG (mỗi lần release)

Sau khi setup xong Phần A + B + C, **mỗi lần muốn deploy chỉ cần**:

```bash
# Trên máy local
git add .
git commit -m "feat: mô tả thay đổi"
git push origin main
```

GitHub Actions tự động chạy pipeline:

```
push lên main
    │
    ▼ Job 1: Build & Push (chạy trên GitHub server)
    ├─ Build Docker image (Vue 3 baked vào, VITE_API_URL từ secret)
    ├─ Push image :latest và :<commit-sha> lên Docker Hub
    │
    ▼ Job 2: Deploy (chỉ chạy sau Job 1 thành công)
    ├─ SSH vào VPS
    ├─ git pull origin main          ← cập nhật docker-compose.prod.yml, nginx config
    ├─ docker pull image:latest      ← kéo image mới về VPS
    ├─ pm2 restart caloeye           ← PM2 gửi SIGTERM → compose down → compose up
    └─ docker image prune -f         ← xóa image cũ giải phóng ổ đĩa
```

Theo dõi tiến trình: **GitHub repo → Actions → tên workflow đang chạy**

Thời gian deploy thường: **3–8 phút** (tùy cache hit).

---

## PHẦN E — CÁC LỆNH THƯỜNG DÙNG TRÊN VPS

### Xem trạng thái tổng quan

```bash
pm2 status
docker compose -f /var/www/app/docker-compose.prod.yml ps
```

### Xem log PM2 (bao gồm log khởi động stack)

```bash
pm2 logs caloeye --lines 100
```

### Xem log từng container

```bash
docker compose -f /var/www/app/docker-compose.prod.yml logs -f backend
docker compose -f /var/www/app/docker-compose.prod.yml logs -f nginx
docker compose -f /var/www/app/docker-compose.prod.yml logs -f postgres
docker compose -f /var/www/app/docker-compose.prod.yml logs -f scheduler
docker compose -f /var/www/app/docker-compose.prod.yml logs -f queue
```

### Vào shell bên trong container

```bash
# Backend (PHP-FPM)
docker compose -f /var/www/app/docker-compose.prod.yml exec backend sh

# PostgreSQL
docker compose -f /var/www/app/docker-compose.prod.yml exec postgres psql -U pt_user -d pt_client
```

### Chạy Artisan thủ công

```bash
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan migrate --force
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan cache:clear
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan config:clear
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan queue:restart
```

### Restart toàn bộ stack thủ công (không cần push code)

```bash
pm2 restart caloeye
```

### Dừng toàn bộ stack

```bash
pm2 stop caloeye
```

### Rollback về commit cụ thể

```bash
# Lấy COMMIT_SHA từ: GitHub → Actions → tên workflow → job Build → step "Build and push" → xem tag được push
docker pull <dockerhub_username>/caloeye-backend:<COMMIT_SHA>

# Sửa .env trỏ về commit đó
nano /var/www/app/.env
# Đổi dòng: DOCKER_IMAGE=<dockerhub_username>/caloeye-backend:<COMMIT_SHA>

pm2 restart caloeye
```

### Dọn image cũ giải phóng ổ đĩa

```bash
docker image prune -f
```

### Gia hạn SSL thủ công

```bash
certbot renew --webroot -w /var/www/app/certbot/www --quiet
docker compose -f /var/www/app/docker-compose.prod.yml exec -T nginx nginx -s reload
```

---

## PHẦN F — CHECKLIST TỔNG (tick lần lượt)

### Phần A — Chuẩn bị tài khoản
- [x] A1 — Tạo Docker Hub repository `caloeye-backend`
- [x] A2 — Tạo Docker Hub Access Token, lưu lại token
- [x] A3 — Đã có GitHub repo với code trên nhánh `main`

### Phần B — GitHub Secrets
- [x] B — `DOCKERHUB_USERNAME` đã khai báo
- [x] B — `DOCKERHUB_TOKEN` đã khai báo (Access Token từ A2)
- [x] B — `VITE_API_URL` đã khai báo (`https://<domain>/api/v1`)
- [x] B — `VPS_HOST` đã khai báo (IP VPS)
- [x] B — `VPS_USER` đã khai báo (`root` hoặc username SSH)
- [x] B — `VPS_SSH_KEY` đã khai báo (private key từ C1)

### Phần C — Setup VPS
- [x] C1 — Tạo SSH deploy key, thêm public key vào `authorized_keys`, copy private key vào GitHub Secret
- [ ] C2 — Cài Docker và Docker Compose plugin
- [ ] C3 — Cài Node.js 20 và PM2
- [ ] C4 — Clone repo về `/var/www/app`, chmod `scripts/start.sh`
- [ ] C5 — Mở firewall (22, 80, 443)
- [ ] C5b — Trỏ A record `caloeye.xyz` và `www.caloeye.xyz` về IP VPS tại Mắt Bão, chờ DNS propagate
- [ ] C6 — Lấy SSL cert Let's Encrypt (`caloeye.xyz` + `www.caloeye.xyz`), tạo `certbot/www`, thêm cron gia hạn
- [ ] C7 — Tạo file `.env` production, điền đầy đủ tất cả biến
- [ ] C8 — Sinh `APP_KEY`, điền vào `.env`
- [ ] C9 — `docker login` bằng Access Token
- [ ] C10 — Build và push image lần đầu (trigger GitHub Actions hoặc build thủ công)
- [ ] C11 — `docker pull` image về VPS
- [ ] C12 — `pm2 start`, `pm2 save`, `pm2 startup` (chạy lệnh PM2 in ra)
- [ ] C13 — Kiểm tra: `pm2 status` online, 5 container healthy, mở được HTTPS

### Phần D — Test deploy tự động
- [ ] Push 1 commit lên `main`, theo dõi GitHub Actions chạy thành công (dấu tích xanh)
- [ ] SSH vào VPS: `pm2 status` → `online`
- [ ] Mở `https://<yourdomain.com>` → app load đúng với code mới
