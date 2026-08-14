<div align="center">
  <img src="./public/svg/AVO-11-thich.svg" alt="AVO Mascot" width="180" />
  <h1>CaloEye – AI Nutrition Tracker</h1>
  <p><em>"Xin chào! 👋 Tôi là AVO - bạn đồng hành dinh dưỡng của bạn"</em></p>
</div>

> ✨ **AVO nói:** "Ăn uống khôn ngoan chưa bao giờ dễ dàng hơn! Cùng tôi, bạn sẽ khỏe mạnh & vui vẻ mỗi ngày!"

AI-powered nutrition tracking app. Snap a photo, get instant food analysis, personalized recommendations, and track your nutrition goals.

---

## 🥑 Gặp AVO - Trợ Lý Dinh Dưỡng AI Của Bạn

**AVO** không chỉ là một ứng dụng - cô ấy là **bạn đồng hành** của bạn trên hành trình sức khỏe! 💚

Cô ấy:

- 🧠 **Thông minh** – AI phân tích ảnh thức ăn, tính dinh dưỡng chính xác
- 💬 **Đồng cảm** – Luôn sẵn sàng tư vấn & động viên bạn
- 📊 **Chăm sóc** – Theo dõi từng bữa ăn, mỗi bước tiến của bạn
- 😊 **Vui vẻ** – Làm cho việc ăn uống trở nên thú vị & bổ ích
- 🎯 **Hiệu quả** – Giúp bạn đạt mục tiêu sức khỏe một cách tự nhiên

> 💬 **AVO: "Hôm nay bạn chọn gì ăn? Hãy chụp ảnh cho tôi xem!"** 📸

## ✨ AVO Giúp Bạn Làm Gì?

| Tính Năng              | AVO Nói Gì?                                                              |
| ---------------------- | ------------------------------------------------------------------------ |
| 📸 **Phân tích ảnh**   | "Chỉ cần chụp ảnh, tôi sẽ nhận diện & tính toán dinh dưỡng!"             |
| 💬 **Tư vấn AI**       | "Chat với tôi về dinh dưỡng, lên kế hoạch ăn, bất cứ điều gì!"           |
| 📊 **Theo dõi**        | "Mỗi bữa ăn, mỗi kg giảm, mỗi ngày - tôi đều ghi nhớ!"                   |
| 🏃 **Tích hợp Strava** | "Biết bạn tập luyện mạnh mẽ, sẽ tính calo đốt cho bạn!"                  |
| 🔐 **Bảo mật**         | "Tài khoản bạn an toàn - Email, Google, Facebook, hay Passkey đều được!" |
| 📱 **Mobile-first**    | "Bất kỳ lúc, bất kỳ nơi - AVO luôn ở bên cạnh bạn!"                      |
| 👥 **Thử miễn phí**    | "Chưa quyết định? Hãy thử guest mode trước, không cần đăng ký!"          |

## 🛠️ Tech Stack

- **Backend**: Laravel 11 (PHP)
- **Frontend**: Vue 3 + TypeScript + Vite
- **Database**: MySQL/PostgreSQL
- **State**: Pinia
- **Auth**: Sanctum, OAuth (Google/Facebook), WebAuthn
- **AI**: Google Gemini API
- **PWA**: Vite PWA Plugin

## 🚀 Bắt Đầu Với AVO

> 💬 **AVO:** "Muốn chạy ứng dụng để gặp tôi? Đơn giản lắm!"

### 1️⃣ Cài Đặt

```bash
# Backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Frontend
npm install
npm run dev
```

### 2️⃣ Chạy Ứng Dụng

```bash
# Terminal 1: PHP Backend
php artisan serve

# Terminal 2: Vue Frontend
npm run dev
```

### 3️⃣ Gặp AVO! 🥑

Mở browser: `http://localhost:8000` và chào tôi nào! 👋

> 💚 **AVO:** "Mình đã sẵn sàng giúp bạn rồi!"

## 📁 Project Structure

```
app/                 # Laravel backend
├── Http/Controllers/Api/V1/
├── Models/
└── Services/

resources/js/        # Vue 3 frontend
├── pages/           # Page components
├── components/      # Reusable components
├── composables/     # Logic hooks
├── stores/          # Pinia state
└── router/          # Routes
```

## 📚 Key APIs

```
POST   /api/v1/auth/login           # Login
POST   /api/v1/food/analyze         # AI analyze food
POST   /api/v1/chat                 # Chat with AI advisor
POST   /api/v1/food/log             # Log meal
GET    /api/v1/food/today           # Today's stats
```

See [DEPLOY.md](./DEPLOY.md) for full API docs.

## 🤝 Contributing

1. Branch: `git checkout -b feature/name`
2. Commit: Follow `feat:`, `fix:`, `docs:` prefixes
3. Push & create PR

Code style:

- Backend: PSR-12 (Laravel)
- Frontend: ESLint + Prettier

```bash
npm run lint
npm run test
```

## 📄 License

MIT License

## 💬 AVO Muốn Nói Với Bạn...

<div align="center">
  
  ### 🌟 "Hãy bắt đầu ngay hôm nay!"
  
  _"Mỗi bữa ăn thông minh là một bước tiến. Tôi sẽ ở đây, giúp bạn từng ngày._
  
  _Chụp ảnh thức ăn, nhắn tin cho tôi, hoặc kiểm tra tiến độ - dù lúc nào tôi cũng sẵn sàng!_
  
  _Cùng tôi, bạn sẽ không bao giờ cảm thấy một mình. Chúng ta sẽ cùng nhau làm nên điều kỳ diệu!"_ 💚
  
  #### ✨ Hãy thay đổi cuộc sống bạn - Ngay từ bây giờ!
</div>

---

## 👤 Liên Hệ & Support

- 📧 **Email**: fboyquangninh@gmail.com
- 🐛 **Issues**: GitHub Issues
- 💬 **Chat**: Gặp AVO trong app!

> **AVO luôn ở đây cho bạn!** 🥑💚
