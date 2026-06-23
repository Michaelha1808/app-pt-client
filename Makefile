PHP = /d/laragon/bin/php/php-8.4.18-Win32-vs17-x64/php.exe
COMPOSER = $(PHP) /c/ProgramData/ComposerSetup/bin/composer.phar
ARTISAN = $(PHP) artisan

# ─── Docker ────────────────────────────────────────────────────────────────────
.PHONY: up down logs ps

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f

ps:
	docker compose ps

# ─── Backend (local, no Docker) ────────────────────────────────────────────────
.PHONY: serve migrate migrate-fresh seed tinker test

serve:
	$(ARTISAN) serve --port=8000

migrate:
	$(ARTISAN) migrate

migrate-fresh:
	$(ARTISAN) migrate:fresh --seed

seed:
	$(ARTISAN) db:seed

tinker:
	$(ARTISAN) tinker

test:
	$(PHP) vendor/bin/phpunit

# ─── Frontend / Vue SPA (Vite, served by Laravel) ──────────────────────────────
.PHONY: fe-dev fe-build

fe-dev:
	npm run dev

fe-build:
	npm run build

# ─── Setup ─────────────────────────────────────────────────────────────────────
.PHONY: install

install:
	$(COMPOSER) install
	npm install
	cp -n .env.example .env || true
