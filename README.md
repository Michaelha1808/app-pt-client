# 🥗 CaloEye – AI Nutrition Tracker

<p align="center">
  Track calories and get AI-powered personalized nutrition advice in seconds.
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#tech-stack">Tech Stack</a> •
  <a href="#getting-started">Getting Started</a> •
  <a href="#project-structure">Project Structure</a> •
  <a href="#api-documentation">API Docs</a> •
  <a href="#contributing">Contributing</a>
</p>

---

## 📋 About

**CaloEye** is an AI-powered nutrition tracking app that helps users monitor their diet and achieve their fitness goals. Simply snap a photo of your meal, and our AI will identify the food items and provide detailed nutritional information along with personalized recommendations.

### Key Highlights:
- 📸 **AI Food Recognition** – Analyze meals from photos instantly
- 🤖 **Personalized Advice** – Get customized nutrition recommendations from AI
- 📊 **Complete Tracking** – Monitor calories, macros, weight, and streak
- 🔄 **Health Integrations** – Sync with Strava for workout data
- 🔐 **Secure Auth** – Email, OAuth (Google/Facebook), and Passkey (WebAuthn) support
- 📱 **PWA Ready** – Works offline and on mobile like a native app
- 👥 **Guest Mode** – Try before signing up with daily free quotas

---

## ✨ Features

### Core Features
- **Meal Analysis** – Snap a photo and AI identifies dishes + calculates nutrition
- **Meal Logging** – Log meals manually or re-use favorites
- **Nutrition Dashboard** – Track daily calories, protein, carbs, fats
- **AI Chat Advisor** – Get personalized meal plans and nutrition tips
- **Preferences & Restrictions** – Remember dietary preferences, allergies, dislikes

### User Management
- **Profile** – Manage height, weight, calorie goals, personal data
- **Email Verification** – Secure account validation
- **Passkey Authentication** – Fingerprint/Face ID login (WebAuthn)
- **Avatar** – Upload custom profile pictures

### Tracking & History
- **Daily Meals** – View all meals logged today with calorie totals
- **Meal History** – Browse past meals by date
- **Frequent Meals** – Quick-add meals you eat regularly
- **Weight Tracker** – Log weight trends and track progress
- **Streak System** – Motivational daily logging streak with rewards

### Integrations & Notifications
- **Strava Integration** – Auto-sync workouts and calories burned
- **Manual Activities** – Log custom workouts
- **Push Notifications** – Get reminders and updates
- **Notification Preferences** – Customize reminder times

### Admin Features
- **User Management** – View, edit, suspend, delete users
- **Settings Control** – Enable/disable features (OAuth, registration, guest mode)
- **Audit Logs** – Track all admin actions
- **System Monitoring** – Health checks, logs, failed job management
- **Food Database** – Manage nutrition database
- **Dataset Review** – Review and improve AI training data

---

## 🛠️ Tech Stack

### Backend
- **Framework**: Laravel 11
- **Language**: PHP
- **Database**: MySQL/PostgreSQL (with Eloquent ORM)
- **API**: RESTful API with Sanctum authentication
- **Queue**: Laravel jobs for background tasks
- **AI**: Google Gemini API for nutrition advice

### Frontend
- **Framework**: Vue 3 (Composition API)
- **Language**: TypeScript
- **Build Tool**: Vite
- **State Management**: Pinia
- **Router**: Vue Router
- **UI Styling**: Tailwind CSS
- **PWA**: Vite PWA Plugin
- **Icons**: SVG-based components

### Authentication & Security
- **API Auth**: Laravel Sanctum (tokens)
- **OAuth**: Google & Facebook login via Laravel Socialite
- **Passkey Auth**: WebAuthn (FIDO2) for biometric login
- **Email Verification**: Custom verification system

### Integrations
- **Strava API** – Sync fitness activities
- **Firebase Cloud Messaging** – Push notifications
- **Google Cloud Storage** – Avatar/image storage (optional)

### DevOps
- **Version**: Tracked in `package.json`
- **PWA**: Workbox-based service worker
- **CI/CD**: GitHub Actions ready
- **Deployment**: Docker-ready with PHP/Laravel setup

---

## 🚀 Getting Started

### Prerequisites
- PHP 8.2+
- Node.js 18+
- MySQL 8.0+ or PostgreSQL 12+
- Composer

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Michaelha1808/app-pt-client.git
   cd app-pt-client
   ```

2. **Install backend dependencies**
   ```bash
   composer install
   ```

3. **Install frontend dependencies**
   ```bash
   npm install
   ```

4. **Setup environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure database**
   ```bash
   # Edit .env with your database credentials
   php artisan migrate
   php artisan db:seed  # (optional)
   ```

6. **Generate storage link**
   ```bash
   php artisan storage:link
   ```

7. **Build frontend**
   ```bash
   npm run build
   ```

8. **Start development server**
   ```bash
   # Terminal 1: PHP server
   php artisan serve
   
   # Terminal 2: Frontend dev server
   npm run dev
   ```

Visit `http://localhost:8000` in your browser.

### Environment Variables
Key `.env` variables:
```env
APP_NAME=CaloEye
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=caloeye
DB_USERNAME=root
DB_PASSWORD=

# AI Configuration
VITE_GEMINI_API_KEY=your_gemini_api_key

# OAuth
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
FACEBOOK_APP_ID=your_facebook_app_id
FACEBOOK_APP_SECRET=your_facebook_app_secret

# Firebase (for notifications)
VITE_FIREBASE_PROJECT_ID=your_project_id
VITE_FIREBASE_MESSAGING_SENDER_ID=your_sender_id
```

---

## 📁 Project Structure

```
app-pt-client/
├── app/                          # Laravel backend
│   ├── Http/
│   │   ├── Controllers/Api/V1/  # API endpoints
│   │   │   ├── AuthController.php
│   │   │   ├── FoodController.php
│   │   │   ├── ChatController.php
│   │   │   ├── PlanController.php
│   │   │   └── ...
│   │   └── Middleware/
│   ├── Models/                   # Database models
│   │   ├── User.php
│   │   ├── MealLog.php
│   │   ├── WeightLog.php
│   │   └── ...
│   ├── Services/                 # Business logic
│   │   ├── EmailVerificationService.php
│   │   ├── SettingsService.php
│   │   └── ...
│   └── ...
├── routes/
│   ├── api_v1.php               # API routes (v1)
│   └── web.php
├── database/
│   ├── migrations/              # Database schema
│   ├── seeders/                 # Sample data
│   └── factories/               # Test factories
├── resources/js/
│   ├── pages/                   # Vue page components
│   │   ├── Home.vue
│   │   ├── Chat.vue
│   │   ├── Profile.vue
│   │   ├── History.vue
│   │   ├── auth/
│   │   ├── admin/
│   │   └── ...
│   ├── components/              # Reusable Vue components
│   │   ├── common/
│   │   ├── profile/
│   │   ├── home/
│   │   └── ...
│   ├── composables/             # Vue composables (logic)
│   │   ├── useAuth.ts
│   │   ├── useChat.ts
│   │   ├── useMealLog.ts
│   │   └── ...
│   ├── stores/                  # Pinia state management
│   │   ├── auth.ts
│   │   └── ...
│   ├── types/                   # TypeScript type definitions
│   ├── utils/                   # Helper utilities
│   ├── router/                  # Vue Router configuration
│   └── app.ts                   # Vue app entry point
├── tests/                       # PHPUnit & Vitest tests
├── vite.config.js               # Vite configuration
├── composer.json                # PHP dependencies
├── package.json                 # Node dependencies
└── README.md                    # This file
```

---

## 📚 API Documentation

The API follows RESTful conventions with versioning (`/api/v1/*`).

### Authentication
```
POST   /api/v1/auth/register           # Create new account
POST   /api/v1/auth/login              # Login with email/password
POST   /api/v1/auth/logout             # Logout (requires token)
GET    /api/v1/auth/me                 # Get current user
POST   /api/v1/auth/refresh            # Refresh token
```

### OAuth
```
GET    /api/v1/auth/google             # Google login redirect
GET    /api/v1/auth/google/callback    # Google callback
GET    /api/v1/auth/facebook           # Facebook login redirect
GET    /api/v1/auth/facebook/callback  # Facebook callback
```

### Food & Nutrition
```
POST   /api/v1/food/analyze            # AI analyze food from image
POST   /api/v1/food/detect             # Multi-dish detection
POST   /api/v1/food/log                # Log meal
GET    /api/v1/food/today              # Today's stats
GET    /api/v1/food/history            # Past meals
GET    /api/v1/food/frequent           # Frequently eaten meals
```

### AI Chat
```
POST   /api/v1/chat                    # Send message to AI advisor (SSE)
POST   /api/v1/chat/apply-plan         # Apply AI-suggested meal plan
```

### Meal Plans
```
GET    /api/v1/plan                    # Get current meal plan
POST   /api/v1/plan/generate           # Generate new AI plan
GET    /api/v1/plan/history            # Past plans
```

### User Management
```
GET    /api/v1/user/profile            # Get user profile
PATCH  /api/v1/user/profile            # Update profile
POST   /api/v1/user/avatar             # Upload avatar
POST   /api/v1/user/change-password    # Change password
```

### Admin (requires role='admin')
```
GET    /api/v1/admin/users             # List all users
GET    /api/v1/admin/stats             # System statistics
PUT    /api/v1/admin/settings          # Update app settings
```

Full API documentation available in [DEPLOY.md](./DEPLOY.md).

---

## 🔐 Guest Mode

Users can try the app without signing up:

**Guest Quotas (per day):**
- Meal Analysis: 1 scan
- AI Chat: 1 message

When guest logs in, their cached data is cleared to avoid confusion with previous account data.

---

## 🤝 Contributing

### Development Workflow
1. Create a feature branch: `git checkout -b feature/your-feature`
2. Follow commit conventions: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`
3. Test your changes thoroughly
4. Submit a PR with clear description

### Code Style
- **Backend**: PSR-12 (via Laravel)
- **Frontend**: ESLint + Prettier (configured in project)
- Run linting: `npm run lint`

### Testing
```bash
# PHP tests
php artisan test

# Frontend tests
npm run test
```

### Build & Deploy
```bash
# Production build
npm run build
php artisan config:cache
php artisan route:cache

# Check deployment checklist in DEPLOY.md
```

---

## 📦 Database Models

Main entities:
- **User** – Registered users + guest data
- **MealLog** – Individual meal entries
- **WeightLog** – Weight tracking history
- **Preference** – User dietary preferences & allergies
- **HealthIntegration** – Strava & other integrations
- **PushNotificationToken** – Device tokens for notifications

---

## 🐛 Known Issues & Roadmap

### Current
- Guest mode data clears on login (by design)
- OAuth callbacks require server HTTPS in production
- Some features admin-controllable via settings

### Roadmap
- [ ] Barcode scanning for quick food lookup
- [ ] Export nutrition reports (PDF)
- [ ] Apple Health integration
- [ ] Multi-language support
- [ ] Offline-first sync improvements
- [ ] Community recipes & meal plans

---

## 📄 License

This project is open-source software licensed under the [MIT license](LICENSE.md).

---

## 👥 Contact & Support

- **Email**: fboyquangninh@gmail.com
- **Issues**: Report bugs on GitHub Issues
- **Discussions**: GitHub Discussions for feature requests

---

## 🙏 Acknowledgments

- Built with ❤️ using Laravel, Vue 3, and AI
- Powered by Google Gemini API
- Icons & UI inspired by iOS design principles

---

**Happy tracking! 🎯**
