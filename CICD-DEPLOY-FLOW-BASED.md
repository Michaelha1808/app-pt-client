# CI/CD Deployment Flow — Node.js TypeScript (GitHub Actions + Docker Hub + PM2 + VPS)

> Tài liệu này mô tả luồng deploy tự động cho dự án backend Node.js/TypeScript. Mục đích: giúp Claude (hoặc bất kỳ ai đọc source code) hiểu nhanh kiến trúc CI/CD đang dùng mà không cần đọc lại toàn bộ workflow file.

## 1. Tổng quan kiến trúc

```
Developer push/merge → branch "main"
        │
        ▼
GitHub Actions (CI/CD pipeline)
        │
        ├─► Build Docker image
        ├─► Push image lên Docker Hub
        │
        ▼
SSH vào VPS
        │
        ├─► Pull image mới nhất từ Docker Hub
        ├─► Dừng & xóa container cũ
        ├─► Chạy container mới (PM2 chạy bên trong container để quản lý tiến trình Node.js)
        │
        ▼
Ứng dụng chạy production trên VPS
```

## 2. Các thành phần chính

| Thành phần | Vai trò |
|---|---|
| **GitHub Actions** | Tự động trigger pipeline khi có commit/merge vào nhánh `main` |
| **Docker Hub** | Lưu trữ Docker image đã build (registry trung gian giữa CI và VPS) |
| **Docker Image/Container** | Đóng gói toàn bộ app + dependencies thành 1 đơn vị chạy độc lập |
| **PM2** | Cài đặt **bên trong** Docker image, quản lý process Node.js (auto-restart, log, cluster mode...) |
| **VPS** | Server thực tế chạy container production, được CI SSH vào để pull & restart |
| **GitHub Secrets** | Lưu trữ thông tin nhạy cảm (tài khoản Docker Hub, file env production, SSH key VPS...) |

## 3. Luồng chi tiết (step-by-step)

### Bước 1 — Trigger
- Khi có `push` **hoặc `pull_request`** vào nhánh `main`, GitHub Actions tự động kích hoạt workflow (`.github/workflows/docker-image.yml`).

### Bước 2 — Build
- Job CI checkout code, sau đó build Docker image từ `Dockerfile` trong repo.
- Trong quá trình build, các biến môi trường nhạy cảm (`.env` production) được inject vào image hoặc container thông qua GitHub Secrets — **không** commit trực tiếp file `.env` vào repo.

### Bước 3 — Push image lên Docker Hub
- Đăng nhập Docker Hub bằng `DOCKERHUB_USERNAME` / `DOCKERHUB_PASSWORD` (lấy từ GitHub Secrets).
- Tag image **cố định** `michaelha/twitter:v4` (tag thủ công theo version, không dùng `:latest`).
- Push image lên Docker Hub repository.

### Bước 4 — Deploy lên VPS
- Job `deploy` (`needs: build`) chỉ chạy sau khi job `build` thành công.
- GitHub Actions SSH vào VPS bằng **username/password** (`HOST`, `HOST_USERNAME`, `HOST_PASSWORD`, `PORT` — không dùng SSH key).
- Trên VPS, thực hiện:
  1. `docker login` lại Docker Hub.
  2. `docker pull` image `v4` mới nhất từ Docker Hub.
  3. `docker stop` & `docker rm` container cũ (`twitter-clone`).
  4. `docker run` container mới với `-d` (chạy nền), map port `3000:3000`, `--restart unless-stopped`, và mount volume `~/twitter-clone/uploads:/app/uploads` để giữ file upload qua các lần deploy.
- Bên trong container, **PM2** chịu trách nhiệm khởi chạy và giám sát tiến trình Node.js (tự restart khi crash, quản lý log...); `--restart unless-stopped` của Docker là lớp bảo vệ ở cấp container, độc lập với PM2 ở cấp process.

### Bước 5 — Hoàn tất
- Ứng dụng chạy production trên VPS với image mới nhất, hoàn toàn tự động từ lúc merge code.

## 4. GitHub Secrets cần khai báo

Khai báo tại: `Repo → Settings → Secrets and variables → Actions → Secrets`

| Secret name | Mục đích |
|---|---|
| `TWITTER_ENV_PRODUCTION` | Toàn bộ nội dung file `.env.production` (DB, JWT secret, API key...), được ghi ra file ngay trong bước build |
| `DOCKERHUB_USERNAME` | Tài khoản đăng nhập Docker Hub |
| `DOCKERHUB_PASSWORD` | Mật khẩu / access token Docker Hub |
| `HOST` | Địa chỉ IP/domain VPS |
| `HOST_USERNAME` | User SSH vào VPS |
| `HOST_PASSWORD` | Mật khẩu SSH vào VPS (workflow dùng password, không dùng SSH key) |
| `PORT` | Port SSH của VPS |

> ⚠️ Không bao giờ hard-code các giá trị này trong code hay commit vào repo. Tất cả đều inject qua `${{ secrets.XXX }}` lúc runtime.

## 5. Workflow thực tế: `.github/workflows/docker-image.yml`

Nguồn: [`duocmmo/nodejs-super`](https://github.com/duocmmo/nodejs-super/tree/main/.github/workflows)

```yaml
name: Docker Image CI
on:
  push:
    branches: ['main']
  pull_request:
    branches: ['main']
jobs:
  build:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: ./Twitter
    steps:
      - uses: actions/checkout@v3
      - name: 'Create env file'
        run: echo "${{ secrets.TWITTER_ENV_PRODUCTION }}" > .env.production
      - name: Build the Docker image
        run: docker build --progress=plain -t michaelha/twitter:v4 .
      - name: Log in to Docker Hub
        run: docker login -u ${{ secrets.DOCKERHUB_USERNAME }} -p ${{ secrets.DOCKERHUB_PASSWORD }}
      - name: Push the Docker image
        run: docker push michaelha/twitter:v4
  deploy:
    runs-on: ubuntu-latest
    needs: build
    steps:
      - name: Executing remote ssh commands using password
        uses: appleboy/ssh-action@v1.0.0
        with:
          host: ${{ secrets.HOST }}
          username: ${{ secrets.HOST_USERNAME }}
          password: ${{ secrets.HOST_PASSWORD }}
          port: ${{ secrets.PORT }}
          script: |
            docker login -u ${{ secrets.DOCKERHUB_USERNAME }} -p ${{ secrets.DOCKERHUB_PASSWORD }}
            docker pull michaelha/twitter:v4
            docker stop twitter-clone
            docker rm twitter-clone
            docker run -dp 3000:3000 --name twitter-clone --restart unless-stopped -v ~/twitter-clone/uploads:/app/uploads michaelha/twitter:v4
```

### Phân tích workflow

**Job `build`** (chạy trên `ubuntu-latest`, working directory `./Twitter`):
1. `actions/checkout@v3` — checkout code repo.
2. **Create env file** — ghi nội dung secret `TWITTER_ENV_PRODUCTION` ra file `.env.production` ngay trong runner, để Dockerfile `COPY` file này vào image khi build (đây là cách phổ biến để đưa biến môi trường production vào image mà không commit `.env` lên repo).
3. **Build image** — `docker build` với tag cố định `michaelha/twitter:v4` (tag theo version thủ công, không dùng `:latest` hay commit SHA).
4. **Login Docker Hub** — dùng `docker login -u ... -p ...` (lưu ý: cách này log password ra log nếu không cẩn thận; cách an toàn hơn là dùng `docker/login-action`, nhưng ở đây làm theo kiểu thủ công cho dễ hiểu bản chất).
5. **Push image** lên Docker Hub.

**Job `deploy`** (`needs: build` → chỉ chạy sau khi build xong):
1. Dùng action `appleboy/ssh-action@v1.0.0` để SSH vào VPS bằng **username/password** (không dùng SSH key).
2. Trên VPS, script thực thi tuần tự:
   - `docker login` lại (vì đây là phiên SSH mới, độc lập với runner build).
   - `docker pull` image `v4` mới nhất.
   - `docker stop twitter-clone` + `docker rm twitter-clone` — dừng và xóa container cũ.
   - `docker run -dp 3000:3000 --name twitter-clone --restart unless-stopped -v ~/twitter-clone/uploads:/app/uploads michaelha/twitter:v4` — chạy container mới:
     - `-d`: detached mode (chạy nền)
     - `-p 3000:3000`: map port
     - `--restart unless-stopped`: tự khởi động lại nếu container crash hoặc VPS reboot (đây là phần thay thế vai trò "luôn chạy" — PM2 bên trong container vẫn quản lý process Node.js, còn Docker quản lý lifecycle của cả container)
     - `-v ~/twitter-clone/uploads:/app/uploads`: mount volume để giữ lại file upload qua các lần deploy (không bị mất khi container bị xóa/tạo lại)

> ⚠️ Lưu ý quan trọng: job `build` chạy trên **cả `push` lẫn `pull_request` vào `main`** — nghĩa là một PR nhắm vào `main` cũng sẽ trigger build (và có thể push image lên Docker Hub nếu secret khả dụng). Nếu không muốn PR cũng build/push, nên tách điều kiện `if: github.event_name == 'push'` cho job build, hoặc tách riêng job `build` (chạy cho PR, không push) và `build-and-push` (chỉ chạy khi push vào `main`).

## 6. Dockerfile thực tế

Nguồn: `Twitter/Dockerfile`

```dockerfile
FROM node:20-alpine3.16
WORKDIR /app
COPY package.json .
COPY package-lock.json .
COPY tsconfig.json .
COPY ecosystem.config.js .
COPY .env.production .
COPY ./src ./src
COPY ./openapi ./openapi
RUN apk update && apk add bash
RUN apk add --no-cache ffmpeg
RUN apk add python3
RUN npm install pm2 -g
RUN npm install
RUN npm run build
EXPOSE 3000
CMD ["pm2-runtime", "start", "ecosystem.config.js", "--env", "production"]
```

### Phân tích Dockerfile

| Dòng | Ý nghĩa |
|---|---|
| `FROM node:20-alpine3.16` | Base image Node 20 bản Alpine (nhẹ) |
| `WORKDIR /app` | Toàn bộ thao tác sau đó diễn ra trong thư mục `/app` của container |
| `COPY package.json .` / `COPY package-lock.json .` | Copy riêng 2 file này trước (tận dụng Docker layer cache — nếu code thay đổi nhưng dependency không đổi thì không phải `npm install` lại) |
| `COPY tsconfig.json .` | Cấu hình build TypeScript |
| `COPY ecosystem.config.js .` | **File cấu hình PM2** — đây chính là nơi khai báo app chạy thế nào (entry file, số instance, env...), được PM2 đọc khi `pm2-runtime start ecosystem.config.js` chạy |
| `COPY .env.production .` | File env production — chính là file được tạo ra ở bước **"Create env file"** trong GitHub Actions (`echo "${{ secrets.TWITTER_ENV_PRODUCTION }}" > .env.production`) rồi mới `docker build`, nên lúc build Docker đã có sẵn file này để copy vào image |
| `COPY ./src ./src`, `COPY ./openapi ./openapi` | Copy source code TypeScript và tài liệu OpenAPI |
| `RUN apk add bash / ffmpeg / python3` | Cài thêm dependency hệ thống (ffmpeg dùng cho xử lý media — ví dụ encode video/HLS cho tính năng upload video kiểu Twitter clone) |
| `RUN npm install pm2 -g` | Cài **PM2 global trong image** — đây là nơi "Docker biết PM2" |
| `RUN npm install` | Cài dependency của project |
| `RUN npm run build` | Build TypeScript → JavaScript (thường ra thư mục `dist/`) |
| `EXPOSE 3000` | Khai báo port app lắng nghe (chỉ mang tính document, thực tế map port là do `-p 3000:3000` lúc `docker run`) |
| `CMD ["pm2-runtime", "start", "ecosystem.config.js", "--env", "production"]` | **Đây chính là câu lệnh chạy app + chạy PM2 mà bạn tìm.** Khi container khởi động, Docker tự động chạy lệnh này: dùng `pm2-runtime` (bản PM2 dành riêng cho Docker — chạy ở foreground, log ra stdout để `docker logs` đọc được, và không bị PM2 daemon hóa) để start app theo cấu hình trong `ecosystem.config.js`, với môi trường `production`. |

> 💡 Vậy là chuỗi liên kết đầy đủ: **GitHub Secrets (`TWITTER_ENV_PRODUCTION`) → ghi ra `.env.production` trong runner → `COPY .env.production` vào image lúc build → `ecosystem.config.js` định nghĩa app chạy ra sao → `CMD pm2-runtime start ecosystem.config.js --env production` chạy app khi container start.** Lệnh `node dist/index.js` thật sự nằm **bên trong** `ecosystem.config.js` (key `script`), không phải gọi trực tiếp trong Dockerfile — xem chi tiết ở mục 7.

## 7. `ecosystem.config.js` thực tế — mảnh ghép cuối cùng

Nguồn: `Twitter/ecosystem.config.js`

```js
module.exports = {
  apps: [
    {
      name: 'twitter',
      script: 'node dist/index.js',
      env: {
        NODE_ENV: 'development',
        TEN_BIEN: 'Gia tri'
      },
      env_production: {
        NODE_ENV: 'production'
      }
    }
  ]
}
```

### Phân tích

| Key | Ý nghĩa |
|---|---|
| `name: 'twitter'` | Tên process hiển thị trong `pm2 list` / `pm2 logs` (đây là lý do `docker exec -it twitter-clone pm2 logs` ra log của process tên `twitter`) |
| `script: 'node dist/index.js'` | **Đây chính là lệnh thật sự chạy app** — entry point sau khi `npm run build` (TypeScript đã compile ra `dist/index.js`). PM2 sẽ tự `spawn` lệnh này và giám sát process đó. |
| `env` | Biến môi trường mặc định khi chạy **không** kèm flag `--env` |
| `env_production` | Biến môi trường **được merge thêm** khi PM2 chạy kèm flag `--env production` (chính là flag được truyền trong `CMD` của Dockerfile) |

### Ráp lại toàn bộ chuỗi "ai chạy app, chạy bằng cách nào"

```
Dockerfile:
  CMD ["pm2-runtime", "start", "ecosystem.config.js", "--env", "production"]
        │
        ▼
PM2 đọc ecosystem.config.js, thấy app "twitter"
        │
        ▼
script: "node dist/index.js"  ← lệnh thực sự chạy app (Node.js process)
        │
        ▼
flag "--env production" → merge thêm env_production { NODE_ENV: 'production' }
        │
        ▼
App chạy với NODE_ENV=production, được PM2 giám sát (tự restart nếu crash)
```

> ⚠️ Lưu ý: `env_production` ở đây chỉ có `NODE_ENV: 'production'` — **không** chứa các biến nhạy cảm (DB URL, JWT secret...). Các biến đó nằm trong file `.env.production` (được copy vào image ở bước `COPY .env.production .` trong Dockerfile) và được app đọc qua thư viện kiểu `dotenv` ngay trong code Node.js, **độc lập** với cơ chế `env`/`env_production` của PM2. Hai nguồn biến môi trường này tách biệt nhau: một bên do PM2 quản lý (chỉ `NODE_ENV`), một bên do app tự đọc file `.env.production` lúc khởi động.

## 8. Checklist khi setup lại từ đầu

- [ ] Tạo Docker Hub repository
- [x] Dockerfile đã có sẵn — `COPY .env.production`, `COPY ecosystem.config.js`, cài `pm2-runtime` toàn cục, `CMD pm2-runtime start ecosystem.config.js --env production`
- [ ] Khai báo đầy đủ GitHub Secrets: `TWITTER_ENV_PRODUCTION`, `DOCKERHUB_USERNAME`, `DOCKERHUB_PASSWORD`, `HOST`, `HOST_USERNAME`, `HOST_PASSWORD`, `PORT`
- [ ] Viết workflow `.github/workflows/docker-image.yml`
- [ ] Trên VPS: cài Docker, mở port `3000`, tạo sẵn thư mục `~/twitter-clone/uploads` để mount volume
- [ ] Test thử 1 lần deploy thủ công trước khi để CI tự động chạy
- [ ] Kiểm tra log PM2 trong container (`docker exec -it twitter-clone pm2 logs`) để xác nhận app chạy ổn định
- [ ] (Cân nhắc) Tách job `build` chỉ chạy push, tránh PR cũng push image lên Docker Hub

---
*File này dùng làm tài liệu nội bộ để Claude/AI assistant hiểu nhanh kiến trúc CI/CD của dự án khi đọc source code.*
