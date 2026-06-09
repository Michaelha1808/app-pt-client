# app-pt-client

A cross-platform Progressive Web App (PWA) for the AI-powered Personal Trainer application — runs on any device via browser with a native-like experience.

## Overview

This client application allows users to track their daily calorie intake, log meals by uploading food photos, set personal health goals, and receive AI-powered nutrition advice — all from a single app that works on mobile and desktop.

## Features

- AI-powered food recognition via photo upload
- Daily calorie tracking and BMI analysis
- Meal history and progress overview
- Activity logging for calorie burn estimation
- Motivational notifications, badges, and streak rewards
- Installable on mobile and desktop (PWA)

## Tech Stack

- [Laravel](https://laravel.com/) — backend-for-frontend / SSR & routing
- [Vue.js](https://vuejs.org/) — reactive UI components
- PWA — installable, offline-capable, cross-platform

## Getting Started

```bash
git clone https://github.com/<your-username>/app-pt-client.git
cd app-pt-client
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run dev
```

## Related

- Backend: [app-pt-server](https://github.com/<your-username>/app-pt-server)

## License

MIT
