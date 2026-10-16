# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
dc = $(SHELL) -c '$(COMPOSE_BIN) $(1)'

.PHONY: help up down build install shell logs test test-coverage cache-clear update-bundle ensure-up restart setup setup-db verify assets-install assets test-e2e demo-screenshots

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
export DOCKER_CONFIG := $(BUNDLE_ROOT)/.docker
SERVICE_PHP := php
PNPM := pnpm

help:
	@echo "Symfony 8 Demo - Chat Kit Bundle"
	@echo ""
	@echo "  make up             Start containers"
	@echo "  make down           Stop containers"
	@echo "  make restart        Restart containers"
	@echo "  make build          Build images (no cache)"
	@echo "  make install        Install Composer + pnpm and build Vite assets"
	@echo "  make assets         pnpm install + Vite build (Pentatrion)"
	@echo "  make assets-install Publish bundle IIFE to public/bundles/"
	@echo "  make setup          Create database, schema and demo seed (alias: setup-db)"
	@echo "  make shell          Open shell in PHP container"
	@echo "  make test           Run tests"
	@echo "  make verify         Ensure up and require HTTP 200 on PORT (REQ-TEST-011)"
	@echo "  make update-deps    composer update inside the demo (REQ-MAKE-008)"
	@echo "  make update-bundle  Refresh bundle autoload, assets, and cache"
	@echo "  make test-e2e       Playwright e2e (REQ-DEMO-013)"
	@echo "  make demo-screenshots  Refresh docs/images/demo/*.webp"

# REQ-DEMO-005: canonical up — no build here (use `make build`); Waiting…, Installing…, Demo started at: from PORT
up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@$(call dc,up -d)
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction)
	@$(MAKE) assets-install
	@$(MAKE) assets
	@$(MAKE) setup-db
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(call dc,down)

restart: ensure-up
	@$(call dc,restart)

build:
	@$(call dc,build --no-cache)

ensure-up:
	@if ! $(call dc,exec -T php true) >/dev/null 2>&1; then \
		echo "Starting container..."; \
		$(call dc,up -d --build); \
		sleep 8; \
		$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction); \
		$(MAKE) assets-install; \
		$(MAKE) assets; \
	fi

install: ensure-up
	@$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction)
	@$(MAKE) assets-install
	@$(MAKE) assets

assets-install: ensure-up
	@$(call dc,exec -T php php bin/console assets:install public --symlink --relative --no-interaction)

assets: ensure-up
	@$(call dc,exec -T php pnpm install)
	@$(call dc,exec -T php pnpm rebuild esbuild)
	@$(call dc,exec -T php pnpm run build)
	@echo "Restarting FrankenPHP worker so Pentatrion reloads entrypoints.json..."
	@$(call dc,restart php)
	@sleep 3

# REQ-MAKE-003: `setup` prepares schema + fixtures (setup-db kept as the historical name).
setup: setup-db

setup-db: ensure-up
	@$(call dc,exec -T php php bin/console doctrine:database:create --if-not-exists --no-interaction) || true
	@$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction) || true
	@$(call dc,exec -T php php bin/console app:demo:seed --no-interaction)

shell: ensure-up
	@$(call dc,exec php sh)

logs:
	@$(call dc,logs -f)

# REQ-DEMO-007: sync mounted bundle before tests
test: ensure-up update-bundle setup-db
	@$(call dc,exec -e APP_ENV=test php composer test)

test-coverage: ensure-up setup-db
	@$(call dc,exec -e APP_ENV=test php composer test-coverage)

# REQ-MAKE-003 / REQ-TEST-011: final check — HTTP 200 on PORT (retries while FrankenPHP warms up).
verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	code=000; \
	for i in $$(seq 1 30); do \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT/ 2>/dev/null || echo "000"); \
		[ "$$code" = "200" ] && { echo "OK (HTTP 200 on http://127.0.0.1:$$PORT/)"; exit 0; }; \
		sleep 2; \
	done; \
	echo "Failed (HTTP $$code on http://127.0.0.1:$$PORT/)"; exit 1

cache-clear: ensure-up
	@$(call dc,exec php php bin/console cache:clear)

update-bundle: ensure-up
	@$(call dc,exec php composer dump-autoload --optimize)
	@$(MAKE) assets-install
	@$(MAKE) assets
	@$(call dc,exec php php bin/console cache:clear --env=dev --no-warmup) || true
	@echo "Bundle updated. Reload the page in the browser."

# REQ-MAKE-008: update-deps — optional monorepo helper (REQ-MAKE-009: soft include only).
# A standalone clone gets the in-repo fallback below (ensure-up + composer update in the container).
DEMO_UPDATE_DEPS_MK := $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
ifneq (,$(wildcard $(DEMO_UPDATE_DEPS_MK)))
-include $(DEMO_UPDATE_DEPS_MK)
else
.PHONY: update-deps
update-deps: ensure-up
	@$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer update --no-interaction)
endif

# Playwright e2e + README screenshots (REQ-DEMO-013). Uses the official Playwright image.
# PORT is read canonically: .env, then .env.example (tr -d '\r' for CRLF files).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"
