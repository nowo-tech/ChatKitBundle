# Prefer Compose V2; fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN)
dc = $(SHELL) -c '$(COMPOSE_BIN) $(1)'
SERVICE_PHP = php

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
export DOCKER_CONFIG := $(BUNDLE_ROOT)/.docker

.PHONY: help ensure-up up down down-dev build shell install assets assets-test test-ts test test-coverage \
	check-twig-extra test-with-db test-coverage-with-db coverage-check test-coverage-100 cs-check cs-fix \
	rector rector-dry phpstan validate-phpdoc qa release-check release-check-demos demo-smoke composer-sync \
	clean update update-deps validate validate-translations setup-hooks check-no-cursor-coauthor check-open-prs \
	strip-cursor-coauthor-from-history igor

help:
	@echo "Chat Kit Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container: up down down-dev build shell"
	@echo "Dependencies: install assets"
	@echo "Tests: test test-coverage test-with-db test-coverage-with-db test-ts"
	@echo "Quality: cs-check cs-fix rector rector-dry phpstan qa validate-translations"
	@echo "Release: release-check demo-smoke composer-sync"
	@echo "Cleanup: clean"
	@echo "Composer: update update-deps validate"

ensure-up:
	@mkdir -p "$(BUNDLE_ROOT)/.docker"
	@test -f "$(BUNDLE_ROOT)/.docker/config.json" || printf '%s\n' '{}' > "$(BUNDLE_ROOT)/.docker/config.json"
	@$(COMPOSE) ps -q $(SERVICE_PHP) >/dev/null 2>&1 || true
	@$(COMPOSE) up -d --build
	@sleep 2
	@$(COMPOSE) exec -T $(SERVICE_PHP) sh -lc 'test -d vendor || composer install --no-interaction'
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) sh -lc 'test -d node_modules || pnpm install'

up:
	@$(MAKE) ensure-up

down:
	@$(COMPOSE) down

down-dev: down

build:
	@$(COMPOSE) build --no-cache

shell:
	@$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install

assets: ensure-up
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run build
	@echo "Assets: src/Resources/public/js/nowo-chat.js"

test-ts: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run test:coverage | tee coverage-ts.txt
	@./.scripts/ts-coverage-percent.sh coverage-ts.txt

assets-test: test-ts

test: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test

test-with-db: test

test-coverage: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	@./.scripts/php-coverage-percent.sh coverage-php.txt

coverage-check: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) sh -lc 'composer test-coverage | tee coverage-php.txt'
	@./.scripts/coverage-fail-under.sh coverage-php.txt 99

test-coverage-100: coverage-check

test-coverage-with-db: test-coverage

cs-check: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector-dry: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

rector: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

validate-phpdoc: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/complete-public-phpdoc.php

phpstan: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: cs-check phpstan test test-ts

check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor
release-check: check-no-cursor-coauthor check-open-prs check-twig-extra ensure-up assets composer-sync validate-phpdoc cs-fix cs-check rector-dry phpstan igor coverage-check test-ts validate-translations release-check-demos

release-check-demos:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else true; fi

demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-verify; else echo "No demo/Makefile"; exit 1; fi

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-install

clean:
	rm -rf vendor node_modules .pnpm-store coverage coverage-ts .phpunit.cache coverage.xml .php-cs-fixer.cache coverage-php.txt coverage-ts.txt

update: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update

validate: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

validate-translations: ensure-up
	@python3 .scripts/check-translation-key-parity.py
	@$(COMPOSE) exec -T $(SERVICE_PHP) php -r 'require "vendor/autoload.php"; foreach (glob("src/Resources/translations/*.yaml") as $$f) { Symfony\Component\Yaml\Yaml::parseFile($$f); } echo "YAML parse OK\n";'

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@./.scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
