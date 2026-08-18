# CaloEye — Tổng hợp hạ tầng & CI/CD (tài liệu ôn tập bảo vệ đồ án)

> Tài liệu cá nhân, tổng hợp lại toàn bộ phần **DevOps/hạ tầng** bạn phụ trách trong đồ án CaloEye, dựa trên đúng những gì đã triển khai thật (không phải kế hoạch/dự định). Nguồn: `DEPLOY.md`, `DEPLOY_DETAIL_MICHAELHA.md`, `docker-compose.prod.yml`, `docker/php/entrypoint.sh`, `.github/workflows/deploy.yml`.
>
> **Trạng thái:** đã deploy xong, domain **https://caloeye.xyz** đang chạy production (checklist Phần F trong `DEPLOY_DETAIL_MICHAELHA.md` đã tick hết).

---

## 0. Vai trò của bạn trong nhóm

Nhóm làm CaloEye — app chụp ảnh nhận diện món ăn + trợ lý dinh dưỡng AI (Laravel 13 + Vue 3 SPA + PostgreSQL + Gemini API). Bạn phụ trách **toàn bộ phần hạ tầng & vận hành**:

- Thiết kế kiến trúc container hóa cho 1 app Laravel+Vue (không phải Node.js như project mẫu nhóm từng làm trước — phải tự điều chỉnh vì Laravel đọc `.env` lúc runtime chứ không bake vào image như Node).
- Dựng VPS Ubuntu, trỏ domain, cấp SSL.
- Viết pipeline CI/CD tự động: push code → tự build → tự deploy, không cần thao tác tay trên server.
- Thiết kế chiến lược lưu trữ bền vững cho dữ liệu (DB + ảnh món ăn user upload) qua nhiều lần deploy/container bị xóa-tạo lại.
- Vận hành: theo dõi log, rollback khi lỗi, backup, gia hạn SSL tự động.

---

## 1. Sơ đồ tổng thể

```
┌──────────────┐   git push origin main
│  Máy dev     │ ─────────────────────────────┐
└──────────────┘                               ▼
                                    ┌────────────────────────┐
                                    │   GitHub Actions        │
                                    │   .github/workflows/    │
                                    │   deploy.yml             │
                                    └──────────┬───────────────┘
                             ┌──────────────────┼──────────────────┐
                             ▼ Job "build"                          ▼ Job "deploy" (needs: build)
                    Build multi-stage Docker image          SSH vào VPS (SSH key, không password)
                    (Vue 3 build → PHP-FPM image)                    │
                    Push :latest + :<commit-sha>              ┌──────┴───────────────────────┐
                    lên Docker Hub                             │ git pull (cập nhật compose,   │
                                                                 │ nginx conf — không nằm trong  │
                                                                 │ image)                        │
                                                                 │ docker pull image:latest      │
                                                                 │ pm2 restart caloeye           │
                                                                 │ docker image prune -f         │
                                                                 └──────┬───────────────────────┘
                                                                        ▼
                                                        PM2 (chạy trên HOST VPS, không trong container)
                                                        gửi SIGTERM cho scripts/start.sh
                                                                │
                                                        trap → docker compose down (dừng sạch)
                                                                │
                                                        PM2 start lại → docker compose up
                                                                │
                        ┌───────────────┬───────────────┬───────────────┬───────────────┐
                        ▼               ▼               ▼               ▼               ▼
                    postgres        backend         scheduler        queue           nginx
                  (DB, volume    (PHP-FPM, API,    (cron Laravel,  (queue:work,   (port 80/443,
                   postgres_data)  entrypoint.sh    schedule:work)  gửi mail/push)  reverse proxy,
                                   migrate+cache)                                   SSL, serve
                                                                                     Vue static)
```

Domain `caloeye.xyz` → DNS A record (Mắt Bão) → IP VPS → nginx (443, Let's Encrypt) → proxy vào `backend:9000` (PHP-FPM) hoặc trả thẳng file tĩnh Vue.

---

## 2. Hạ tầng máy chủ thực tế

| Mục | Giá trị |
|---|---|
| OS | Ubuntu 22.04.1 LTS (kernel 5.15.0-46-generic, x86_64) |
| Disk | 54.04 GB, đang dùng ~18.1% |
| RAM | đang dùng ~13% |
| Domain | `caloeye.xyz` (đăng ký + quản lý DNS tại **Mắt Bão**) |
| Reverse proxy / SSL | nginx (container) + Let's Encrypt (certbot, chạy trực tiếp trên host, mount cert vào container nginx qua volume) |
| Registry image | Docker Hub (`<dockerhub_username>/caloeye-backend`) |
| Thư mục app trên VPS | `/var/www/app` (clone từ GitHub, không phải chỉ chứa image) |

**Vì sao clone cả repo về VPS chứ không chỉ pull image?** Docker image chỉ đóng gói code PHP + Vue build (thứ *bất biến*). Còn `docker-compose.prod.yml`, `docker/nginx/prod.conf`, `docker/php/php.ini`, `ecosystem.config.cjs`, `scripts/start.sh` là **cấu hình hạ tầng**, đổi độc lập với code app, nên để ở host và cập nhật bằng `git pull` — không cần rebuild image nếu chỉ sửa nginx config.

---

## 3. Docker & Docker Compose

### 3.1 Dockerfile multi-stage (`docker/php/Dockerfile`)

```
Stage 1 (frontend-builder, node:20-alpine)
  → npm ci
  → npm run prod   (Vite build, đọc build-arg VITE_API_URL, bake cứng URL API vào JS bundle)
  → output: public/build/

Stage 2 (php:8.4-fpm-alpine)
  → cài extension: pdo_pgsql, pgsql, mbstring, zip, exif, pcntl, bcmath, gd
  → composer install --no-dev --optimize-autoloader
  → COPY --from=frontend-builder public/build → nhúng frontend đã build vào image backend
  → entrypoint.sh làm điểm khởi động
```

**Điểm quan trọng khi hỏi "vì sao 1 image mà chứa cả FE lẫn BE":** đơn giản hóa triển khai — chỉ build/push/pull 1 image duy nhất, không cần đồng bộ version 2 image riêng. Đánh đổi: mỗi lần đổi 1 dòng JS cũng phải rebuild lại toàn bộ image (kể cả PHP không đổi) — chấp nhận được ở quy mô đồ án.

### 3.2 5 service trong `docker-compose.prod.yml`

| Service | Image | Vai trò | Giới hạn RAM |
|---|---|---|---|
| `postgres` | `postgres:16-alpine` | Database, có `healthcheck` (`pg_isready`) | 256MB (tuned: `shared_buffers=64MB`, `max_connections=20`) |
| `backend` | image từ Docker Hub | PHP-FPM chạy API Laravel, chạy `entrypoint.sh` (migrate + cache) lúc start | 256MB |
| `scheduler` | cùng image `backend` | `php artisan schedule:work` — chạy các Console Command định kỳ (nhắc nhở uống nước, streak, thông báo sáng/trưa/tối...) | 128MB |
| `queue` | cùng image `backend` | `php artisan queue:work --tries=3 --timeout=90` — xử lý job async (gửi mail, gửi push, extract AI...) | 128MB |
| `nginx` | `nginx:alpine` | Reverse proxy, serve static Vue, terminate SSL, expose 80/443 | 64MB |

`scheduler` và `queue` dùng **chung 1 image** với `backend` nhưng override `entrypoint` — không cần build 3 image riêng cho 3 tiến trình PHP khác nhau.

Cả 3 service PHP (`backend`, `scheduler`, `queue`) đều `depends_on: backend: condition: service_healthy` (trừ backend tự phụ thuộc postgres) — đảm bảo thứ tự khởi động đúng: **postgres khỏe → backend khỏe (migrate xong) → scheduler/queue/nginx mới start**.

### 3.3 Biến môi trường: bake-time vs runtime — điểm hay bị hỏi

| Loại biến | Ví dụ | Khi nào "chốt" giá trị | Đổi được không nếu không rebuild? |
|---|---|---|---|
| **Bake-time** (Vite build-arg) | `VITE_API_URL` | Lúc `docker build`, Vite ghi thẳng vào file JS tĩnh | ❌ Không — phải rebuild + push image mới |
| **Runtime** (Laravel `.env`) | `APP_KEY`, `DB_PASSWORD`, `GEMINI_API_KEY` | Đọc từ file `.env` trên **host VPS** lúc container start, không nằm trong image | ✅ Có — sửa `.env` trên VPS rồi `pm2 restart caloeye` là đủ, không cần đụng CI/CD |

Đây là khác biệt cốt lõi so với kiến trúc Node.js mẫu (`CICD-DEPLOY-FLOW-BASED.md`) mà nhóm từng dùng: Node bake cả `.env.production` vào image lúc build (`COPY .env.production .`), còn Laravel đọc `.env` runtime từ volume mount trên host — nên `.env` **không bao giờ đi qua Docker Hub / GitHub Actions**, chỉ tồn tại trên VPS.

---

## 4. Lưu trữ dữ liệu qua Docker volumes (trọng tâm)

```yaml
volumes:
  postgres_data:   # dữ liệu DB
  app_storage:      # Laravel storage/ (ảnh upload, log, session file nếu có)
  app_public:       # build Vue 3 (dùng chung giữa backend và nginx)
```

### 4.1 Vì sao cần volume — bài toán cốt lõi

Container là **stateless theo thiết kế**: mỗi lần deploy, container `backend` cũ bị `docker compose down`, container mới được tạo lại từ image mới (`docker compose up`). Nếu ảnh món ăn user upload nằm trong filesystem của container (`storage/app/public/...`) mà không mount volume, **ảnh sẽ mất sạch mỗi lần deploy**. Named volume (`app_storage`) sống độc lập với vòng đời container → tồn tại xuyên suốt qua mọi lần container bị xóa/tạo lại.

### 4.2 Cơ chế thực tế — symlink `storage:link` + volume chia sẻ

Đây là phần hay bị hỏi nhất vì có 2 tầng: **Laravel symlink** (cách Laravel serve file upload qua HTTP) chồng lên **Docker volume** (cách giữ file bền vững qua deploy) — và thêm 1 bước nữa vì `nginx` là container **khác** với `backend` nên không tự thấy file của backend.

```
backend container start → entrypoint.sh chạy:
  1. php artisan storage:link --force
     → tạo symlink: public/storage → storage/app/public
     (đây là cách chuẩn của Laravel: ảnh lưu vật lý ở storage/app/public,
      nhưng serve ra URL công khai qua public/storage)

  2. cp -a /var/www/public/. /var/www/public-shared/
     → copy toàn bộ public/ (gồm cả symlink storage vừa tạo) sang volume app_public
     (vì sao phải copy: nginx là CONTAINER RIÊNG, không có filesystem chung
      với backend — chỉ chia sẻ được qua named volume)

  3. ln -sfn /var/www/storage/app/public /var/www/public-shared/storage
     → tạo lại symlink NGAY TRONG volume app_public, trỏ sang volume app_storage
     (vì bước 2 chỉ copy được symlink dưới dạng "đường dẫn tương đối" của
      container backend, cần re-link cho đúng ngữ cảnh volume dùng chung)
```

```
Volume app_storage  ←── backend ghi ảnh vào storage/app/public/xxx.jpg
       ▲
       │ symlink (bước 3)
       │
Volume app_public/storage  ──▶  nginx đọc file tĩnh, trả thẳng cho browser
       (nginx mount app_public:/var/www/public:ro — READ-ONLY,
        nginx không bao giờ ghi, chỉ backend mới ghi ảnh)
```

**Tại sao thiết kế 2 volume riêng (`app_storage` + `app_public`) thay vì gộp 1:** tách rõ trách nhiệm — `app_storage` là dữ liệu **do backend sở hữu và ghi** (ảnh upload, log), `app_public` là **artifact build tĩnh** (Vue JS/CSS + symlink trỏ sang ảnh) mà nginx chỉ cần đọc. nginx mount `app_public` ở chế độ `:ro` — kể cả nginx bị compromise cũng không ghi/xóa được file gốc.

### 4.3 Database

`postgres_data` là named volume mount vào `/var/lib/postgresql/data` — dữ liệu DB (user, meal logs, ảnh nhận diện metadata...) sống độc lập với container `postgres`. Container postgres có thể bị `docker compose down/up` thoải mái mà dữ liệu không mất, vì dữ liệu thật nằm ở volume trên host Docker (`/var/lib/docker/volumes/...`), không nằm trong container.

> Lưu ý khi trình bày: hiện tại **chưa có cơ chế backup DB tự động** ra ngoài VPS (chỉ có volume persist tại chỗ) — nếu hội đồng hỏi về backup/disaster recovery, đây là điểm thẳng thắn nên nói "đã nhận diện, chưa triển khai trong phạm vi đồ án" thay vì nói đã có.

---

## 5. CI/CD pipeline chi tiết (`.github/workflows/deploy.yml`)

### Trigger
`push` vào nhánh `main` → chạy 2 job tuần tự: `build` → `deploy` (`needs: build`).

### Job `build`
1. Checkout code.
2. `docker/login-action` đăng nhập Docker Hub bằng `DOCKERHUB_USERNAME` + `DOCKERHUB_TOKEN` (access token, không phải password — an toàn hơn, thu hồi được độc lập).
3. `docker/setup-buildx-action` — bật BuildKit, hỗ trợ cache.
4. `docker/build-push-action`:
   - Build từ `docker/php/Dockerfile`.
   - `build-args: VITE_API_URL=${{ secrets.VITE_API_URL }}`.
   - Push **2 tag**: `:latest` (VPS luôn pull tag này) và `:<commit-sha>` (dùng để rollback chính xác về 1 commit).
   - `cache-from/cache-to: type=gha` — cache layer Docker trên GitHub Actions, lần build sau nếu `package.json`/`composer.json` không đổi thì skip `npm ci`/`composer install`.

### Job `deploy`
SSH vào VPS bằng **SSH key** (`appleboy/ssh-action`, secret `VPS_SSH_KEY`) — không dùng username/password như project Node.js mẫu trước đó (an toàn hơn, không lộ mật khẩu qua log/replay).

```bash
set -e
cd /var/www/app
git pull origin main        # cập nhật compose/nginx config (không nằm trong image)
docker pull $IMAGE:latest   # kéo image mới
pm2 restart caloeye         # graceful restart toàn bộ stack (chi tiết mục 6)
docker image prune -f       # dọn image cũ, giải phóng ổ đĩa
```

Thời gian deploy thực tế: **3–8 phút** tùy cache hit.

### 6 GitHub Secrets cần khai báo

| Secret | Dùng ở đâu |
|---|---|
| `DOCKERHUB_USERNAME` | login Docker Hub (cả build job lẫn VPS) |
| `DOCKERHUB_TOKEN` | access token Docker Hub (Read/Write/Delete) |
| `VITE_API_URL` | bake vào JS bundle lúc build (`https://caloeye.xyz/api/v1`) |
| `VPS_HOST` | IP/domain VPS để SSH |
| `VPS_USER` | user SSH (`root`) |
| `VPS_SSH_KEY` | private key SSH riêng cho CI (tạo riêng bằng `ssh-keygen`, **không dùng key cá nhân**) |

---

## 6. PM2 chạy trên HOST — không phải trong container

Khác với project Node.js mẫu (PM2 chạy **bên trong** 1 container, quản lý 1 process Node), ở đây có **5 container** cần khởi động/dừng đúng thứ tự cùng lúc → PM2 chạy trên **host VPS**, quản lý không phải 1 process app mà là **1 script bash điều khiển toàn bộ `docker compose` stack**.

### `ecosystem.config.cjs`
```js
{
  name: 'caloeye',
  script: '/var/www/app/scripts/start.sh',
  interpreter: 'bash',
  autorestart: true,
  kill_timeout: 30000,   // đợi tối đa 30s cho "docker compose down" chạy xong trước khi force-kill
}
```

### `scripts/start.sh`
```bash
cleanup() { docker compose down; exit 0; }
trap cleanup SIGTERM SIGINT
docker compose up        # chạy FOREGROUND — đây là process PM2 thực sự đang giám sát
```

### Chuỗi restart khi deploy — hiểu rõ cơ chế "graceful"

```
pm2 restart caloeye
   → PM2 gửi SIGTERM cho tiến trình bash đang chạy "docker compose up"
   → trap bắt được SIGTERM → chạy "docker compose down"
     (dừng sạch cả 5 container: nginx → queue/scheduler → backend → postgres)
   → PM2 thấy process cũ đã thoát (exit 0) → tự start lại scripts/start.sh
   → "docker compose up" chạy lại, lần này pull image mới nhất → 5 container mới
```

**Vì sao không dùng `docker restart` trực tiếp:** vì cần thay **image mới** (đã `docker pull` ở bước trước), `restart` container cũ vẫn dùng image cũ. Phải `down` rồi `up` để Compose đọc lại image mới đã pull.

**`autorestart: true` + `--restart unless-stopped` (không dùng ở đây, khác với Node.js mẫu):** thực tế container Docker ở đây **không** đặt `restart: unless-stopped` làm lớp bảo vệ độc lập cấp container như bên Node — toàn bộ trách nhiệm "luôn chạy" nằm ở PM2 cấp host, vì PM2 cần kiểm soát toàn bộ vòng đời `docker compose up/down` theo 1 tiến trình duy nhất.

---

## 7. SSL / DNS

1. **DNS**: domain `caloeye.xyz` đăng ký & quản lý tại **Mắt Bão** → 2 A record trỏ về IP VPS: `@` (gốc) và `www`.
2. **SSL**: `certbot certonly --standalone -d caloeye.xyz -d www.caloeye.xyz` chạy trực tiếp trên host VPS (không phải trong container — vì cần bind port 80 tạm thời lúc xin cert, trong khi container nginx đang giữ port đó → thực hiện lúc setup lần đầu trước khi start stack, hoặc dùng chế độ webroot cho lần renew sau).
3. Certificate lưu tại `/etc/letsencrypt/` trên host, **mount read-only** vào container nginx (`- /etc/letsencrypt:/etc/letsencrypt:ro`) — nginx trong container đọc được cert mà không cần cài certbot trong image.
4. **Tự động gia hạn**: cron trên host (`0 3 * * *`) chạy `certbot renew --quiet` rồi `docker compose exec nginx nginx -s reload` (reload nginx không cần restart cả container, không downtime).

---

## 8. Vận hành & xử lý sự cố (lệnh thực tế hay dùng)

```bash
# Trạng thái tổng quan
pm2 status
docker compose -f /var/www/app/docker-compose.prod.yml ps

# Log
pm2 logs caloeye --lines 100
docker compose -f /var/www/app/docker-compose.prod.yml logs -f backend

# Artisan thủ công (migrate, clear cache...)
docker compose -f /var/www/app/docker-compose.prod.yml exec -T backend php artisan migrate --force

# Rollback về 1 commit cụ thể (nhờ image được tag :<commit-sha>)
docker pull <dockerhub_username>/caloeye-backend:<COMMIT_SHA>
# sửa DOCKER_IMAGE trong .env trỏ sang tag đó
pm2 restart caloeye

# Restart thủ công không cần push code
pm2 restart caloeye
```

---

## 9. (Bonus) Thông báo phát hành phiên bản — tận dụng lại hạ tầng

Push git tag `vX.Y.Z` → workflow riêng `.github/workflows/announce.yml` → SSH vào VPS, gọi trong container **đang chạy** (không build/deploy lại):
```bash
docker compose exec -T backend php artisan notify:announce-update v1.2.3
```
Lệnh này bắn push notification "đã có bản cập nhật" cho toàn bộ user, tái sử dụng hạ tầng broadcast notification có sẵn (`NotificationCampaign`). Tách riêng khỏi workflow deploy chính vì merge `main` bình thường (fix nhỏ, chưa muốn thông báo) không nên làm phiền user — chỉ khi **chủ động gắn tag** mới coi là "phát hành".

---

## 10. Câu hỏi hội đồng thường gặp + gợi ý trả lời

**Q: Vì sao dùng Docker Hub mà không dùng registry riêng (GitHub Container Registry, self-host)?**
> Đồ án quy mô nhỏ, Docker Hub free tier đủ dùng, tích hợp sẵn action chuẩn (`docker/build-push-action`, `docker/login-action`), không cần quản lý thêm hạ tầng registry.

**Q: Vì sao PM2 chạy trên host mà không để Docker tự quản lý bằng `restart: unless-stopped`?**
> Vì cần điều phối **graceful shutdown đúng thứ tự cho cả cụm 5 container** mỗi lần deploy (đổi image mới), không chỉ 1 container. PM2 cho cơ chế `kill_timeout` + `trap SIGTERM` để đảm bảo `docker compose down` chạy xong trước khi container mới lên, tránh 2 bộ container cùng bind port 80/443 hoặc cùng ghi vào 1 volume.

**Q: File ảnh món ăn/user upload lưu ở đâu, có mất khi deploy không?**
> Lưu trong named volume `app_storage` (mount `/var/www/storage`), độc lập vòng đời container. Serve ra ngoài qua symlink Laravel chuẩn (`storage:link`) được `entrypoint.sh` re-tạo lại trong volume `app_public` mỗi lần container start, để container `nginx` (tách biệt filesystem) đọc được. Ảnh **không mất** qua các lần deploy vì nằm ở volume, không nằm trong image hay filesystem container.

**Q: Biến môi trường nhạy cảm (API key, DB password) có bị lộ qua GitHub/Docker Hub không?**
> Không. Laravel đọc `.env` **runtime** từ file trên host VPS (không commit, không build vào image). Chỉ có `VITE_API_URL` (không nhạy cảm — chỉ là URL API public) được bake vào JS bundle lúc build. Toàn bộ secret khác (`APP_KEY`, `DB_PASSWORD`, `GEMINI_API_KEY`, Firebase credentials) chỉ tồn tại trên VPS, không đi qua CI/CD.

**Q: Nếu deploy lỗi (ví dụ migration lỗi) thì sao?**
> `entrypoint.sh` chạy `php artisan migrate --force` mỗi lần container backend start — nếu migration lỗi, container không healthy, `depends_on: condition: service_healthy` khiến `scheduler`/`queue`/`nginx` không start theo, tránh chạy app ở trạng thái DB sai schema. Xử lý: xem log (`docker compose logs backend`), sửa lỗi, hoặc rollback về image tag commit trước đó (`docker pull ...:<COMMIT_SHA>` + sửa `.env` + `pm2 restart`).

**Q: Vì sao build cả frontend lẫn backend chung 1 image?**
> Đơn giản hóa: chỉ 1 artifact để build/tag/push/pull/rollback, không phải đồng bộ version giữa 2 image riêng. Đánh đổi là image nặng hơn và phải rebuild toàn bộ khi chỉ đổi 1 dòng JS — chấp nhận được ở quy mô đồ án (không có traffic lớn cần tối ưu build time).

**Q: Có test được rollback thật chưa?**
> Có cơ chế sẵn sàng (tag theo `commit-sha`, đổi `DOCKER_IMAGE` trong `.env` rồi `pm2 restart`) — nói rõ mức độ đã test thực tế đến đâu khi được hỏi, tránh nói "đã test" nếu chỉ mới chuẩn bị cơ chế.

**Q: Điểm yếu / hướng cải thiện nếu có thêm thời gian?**
> (1) Chưa có backup DB tự động ra ngoài VPS (offsite backup) — chỉ có volume persist tại chỗ, rủi ro nếu mất cả VPS. (2) Chưa có staging environment riêng — deploy thẳng lên production khi push `main`. (3) Chưa có health-check/alerting chủ động (chỉ xem log thủ công khi có sự cố) — có thể thêm uptime monitoring (UptimeRobot) hoặc Laravel Pulse/Telescope. (4) SSL renew qua cron đơn giản, chưa có alert nếu renew thất bại.

---

*Tài liệu ôn tập cá nhân — tổng hợp từ `DEPLOY.md`, `DEPLOY_DETAIL_MICHAELHA.md`, `docker-compose.prod.yml`, `docker/php/entrypoint.sh`, `.github/workflows/deploy.yml`. Không phải tài liệu chính thức nộp kèm đồ án.*
