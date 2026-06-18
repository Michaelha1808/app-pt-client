---
name: Dev Setup
description: Local development commands, PHP path, and Next.js 16 quirks for this project
type: project
---

**PHP 8.4 path** (system PHP is 7.2 — never use it for this project):
`/d/laragon/bin/php/php-8.4.18-Win32-vs17-x64/php.exe`

**Run backend**: `make serve` (uses PHP 8.4 path in Makefile)
**Run frontend**: `make fe-dev` (runs `next dev --webpack --port 3000`)
**Run migrations**: `make migrate`

**Next.js 16 quirks**:
- Must use `--webpack` flag: Turbopack (default in v16) conflicts with `@ducanh2912/next-pwa`
- `ssr: false` with `next/dynamic` only allowed in Client Components (not Server Components)
- PWA is disabled in dev mode (`disable: NODE_ENV === 'development'`)

**Why:** Next.js 16 changed Turbopack to default, breaking webpack-dependent plugins. The `--webpack` flag is set in package.json scripts.

**How to apply:** Always use `--webpack` flag when running Next.js commands. Any dynamic imports with `ssr: false` must live in a `"use client"` component.
