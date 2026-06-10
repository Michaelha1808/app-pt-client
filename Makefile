PHP = /d/laragon/bin/php/php-8.4.18-Win32-vs17-x64/php.exe
COMPOSER = $(PHP) /c/ProgramData/ComposerSetup/bin/composer.phar
ARTISAN = $(PHP) backend/artisan

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
	cd backend && $(PHP) vendor/bin/phpunit

# ─── Frontend (local, no Docker) ───────────────────────────────────────────────
.PHONY: fe-dev fe-build fe-start

fe-dev:
	cd frontend && npm run dev

fe-build:
	cd frontend && npm run build

fe-start:
	cd frontend && npm run start

# ─── Setup ─────────────────────────────────────────────────────────────────────
.PHONY: install

install:
	cd backend && $(COMPOSER) install
	cd frontend && npm install
	cp -n .env.example .env || true
	cp -n backend/.env.example backend/.env || true
