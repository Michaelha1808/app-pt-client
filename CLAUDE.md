# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

CaloEye — AI-powered nutrition tracking app (Laravel API backend + Vue 3 SPA frontend, served as one app by Vite/Laravel). Users snap food photos for AI analysis (Google Gemini), log meals/water/weight, chat with an AI nutrition coach, get AI-generated meal/workout plans, and can connect Strava for activity data.

## Commands

### Setup
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
```

### Local dev (backend + queue + logs + vite, all at once)
```bash
composer run dev
```
Or individually: `php artisan serve`, `php artisan queue:listen`, `npm run dev`.

Makefile shortcuts also exist: `make serve`, `make migrate`, `make migrate-fresh`, `make seed`, `make tinker`, `make fe-dev`, `make fe-build`, `make test`.

### Frontend build / typecheck
```bash
npm run type-check     # vue-tsc --noEmit
npm run build:dev      # typecheck + vite build --mode development
npm run prod           # typecheck + vite build (production)
```
There is no configured `npm run lint` or `npm run test` script currently, despite what the README's contributing section says — verify with `cat package.json` before relying on either.

### Backend tests
```bash
php artisan test                      # full suite (also runs `composer test`)
php artisan test --filter=testName    # single test by name
php artisan test tests/Feature/WeightControllerTest.php   # single file
vendor/bin/phpunit                    # via phpunit directly (make test)
```
Tests run against an in-memory SQLite DB with array cache/session/queue drivers (see `phpunit.xml`) — no local MySQL/Postgres needed for the test suite.

### PHP code style
```bash
vendor/bin/pint          # Laravel Pint, PSR-12
```

### Docker
```bash
make up      # docker compose up -d --build
make down
make logs
```
`docker-compose.yml` is for local/dev, `docker-compose.prod.yml` for production (Postgres, nginx `docker/nginx/prod.conf`). See `DEPLOY.md` and `CICD-DEPLOY-FLOW-BASED.md` for the full deployment pipeline.

## Architecture

This is a **single Laravel app** — the Vue 3 SPA is not a separate project. Vite (via `laravel-vite-plugin`) builds `resources/js/app.ts` and Laravel serves the built assets; `vite.config.js` aliases `@` → `resources/js`. In dev, `composer run dev` runs the PHP server and Vite dev server concurrently.

### Backend (`app/`)

Thin-controller pattern, no Actions/Repositories/DTO layers currently — controllers call services directly:

- `Http/Controllers/Api/V1/` — one controller per resource; `Admin/` subdirectory holds admin-only controllers (require `admin` middleware + `role=admin`).
- `Services/` — business logic (e.g. `ChatService`, `FoodAnalysisService`, `MealPlanService`, `StreakService`, `PreferenceService`). `Services/Health/` wraps third-party fitness integrations behind a `HealthProvider` interface (`HealthProviderFactory` picks the provider, currently `StravaProvider`).
- `Models/` — Eloquent models. Meal/water/weight are logged as time-series (`MealLog`, `WaterLog`, `WeightLog`), not overwritten fields on `User`.
- `Support/` — small stateless helpers (`AuditLogger`, `UsageTracker`, `VietnameseText` for diacritic-insensitive matching, `DeviceName`).
- `Console/Commands/Notifications/` — scheduled commands that send push/email at different times of day (morning/midday/evening, water reminders, streak-risk, weigh-in, re-engagement). Shared push logic lives in `Concerns/DispatchesUserPush.php`.
- Routes: `routes/api_v1.php` holds virtually all API routes (mounted under `/api/v1`), grouped by feature with `auth:sanctum` and named rate limiters (`throttle:food-analyze`, `throttle:chat`, `throttle:plan-generate`, etc. — defined as custom limiters, not raw numbers, for most AI-cost-sensitive endpoints). `routes/api.php`/`web.php` are mostly unused/framework defaults.

**Auth**: Laravel Sanctum (bearer tokens), plus Google/Facebook OAuth (Socialite) and WebAuthn/passkeys (`lbuchs/webauthn`). Many endpoints support **guest access** (food analyze/detect, chat) with degraded/rate-limited behavior — check `AppConfigController`/`throttle:food-analyze` before assuming auth is required.

**AI integration**: Google Gemini API, no fine-tuning. `ChatService` builds prompts from live DB context (profile, today's nutrition, 7-day averages) and streams responses via SSE — see `docs/ai-architecture.md` for the full target design (structured long-term memory in `user_memories`, rolling conversation summaries, habit-profile derivation, prompt token budgets). **That doc describes both the current state and a planned-but-not-yet-built architecture** — section 12 ("Đối chiếu với codebase hiện tại") lists exactly what's implemented vs. still spec-only; check it before assuming a described table/service exists (e.g. `ai_conversations`, `daily_nutrition` rollup, and `LlmClient`/`PromptBuilder` abstractions do not exist yet — chat history is currently client-held and `Services/` contains Gemini calls directly).

### Frontend (`resources/js/`)

Vue 3 + TypeScript + Vite + Pinia + vue-router, Tailwind v4, shadcn-style UI via `reka-ui`.

- `pages/` — route-level components (`admin/`, `auth/`, `integrations/`, `profile/`, `settings/` subfolders mirror route groups).
- `components/` — organized by feature (`food/`, `home/`, `profile/`, `streak/`, `admin/`, `share/`, `pwa/`, `ios/`, `notifications/`), plus `ui/` for generic primitives.
- `composables/` — one `useX.ts` per feature, this is where most API calls and feature logic live (e.g. `useFoodAnalysis`, `useMealPlan`, `useChat`, `useHealthIntegration`, `usePasskey`).
- `stores/auth.ts` — Pinia auth store (`isLoggedIn`, `isGuest`, `isAdmin`).
- `router/index.ts` — all routes defined in one file with `meta.middleware`: `guest` (redirect if logged in), `auth` (allow guest-mode access), `auth-strict` (must be a real logged-in user), `admin` (must be logged-in admin, else → `/admin/login`). Route guard restores session once per app load via `utils/session.ts`.
- Auto-imports are configured (`unplugin-auto-import`) for Vue/vue-router/Pinia APIs and everything under `composables/`, `utils/`, `stores/` — no manual imports needed for those, and TS declarations are generated into `resources/js/auto-imports.d.ts`.
- PWA: `vite-plugin-pwa` with a custom service worker at `resources/js/sw.ts` (`injectManifest` strategy) — push notifications go through Firebase (`plugins/firebase.ts`).

### Docs worth reading before touching a feature

`docs/spec-*.md` are per-feature implementation specs (auth, food-analysis, food-multi-detect, chat-personalization, meal-plan, notifications, notification-deeplink, quick-log, share-meal, streak, weight-tracking, admin, profile, health-integration). `docs/ai-architecture.md` is the system-level map these specs plug into — read it first when a change touches chat, memory, or prompts, since it explains *why* the data model is shaped the way it is, not just what exists.

## Notes

- Comments and commit-adjacent docs in this repo are frequently written in Vietnamese; don't assume English-only when grepping.
- Rate limiting on AI-cost endpoints (food analyze/detect, chat, plan generate) is deliberate and configurable via runtime `Settings` (see `Admin/SettingsController`) — don't casually raise/remove throttles.
- `ml/` contains a standalone Python dataset-export script (`export_dataset.py`) for the food-recognition model improvement loop (`docs/spec-food-model-improvement.md`), unrelated to the Laravel/Vue app's runtime.
