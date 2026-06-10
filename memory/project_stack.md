---
name: Project Stack
description: Monorepo structure with Laravel 13 backend + Next.js 16 frontend PWA + PostgreSQL
type: project
---

**app-pt-client** is a monorepo with two sub-projects:

- `backend/` — Laravel 13.15 REST API, PHP 8.4, PostgreSQL via Eloquent ORM, Sanctum auth
- `frontend/` — Next.js 16.2.9 PWA, TypeScript, Tailwind CSS 4, App Router

**API structure**: Versioned under `/api/v1/`. Routes defined in `routes/api.php` → `routes/api_v1.php`. Currently only `GET /api/v1/health` is public.

**DB**: PostgreSQL 16. Local credentials: `pt_user / secret / pt_client` on port 5432.

**Why:** New project scaffolded 2026-06-10. Demo page is a realtime clock (updates every 100ms) with an API status badge showing backend health.

**How to apply:** When adding features, follow the versioned API pattern. New controllers go in `app/Http/Controllers/Api/V1/`. New Next.js pages use App Router in `src/app/`.
