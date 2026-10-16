DEMOS := symfony8

.PHONY: help up down update-bundle test-all release-check release-verify clean
.PHONY: up-symfony8 down-symfony8 test-symfony8 update-bundle-symfony8 verify-symfony8 test-e2e-symfony8 test-e2e-all

help:
	@echo "Chat Kit Bundle - Demo Commands"
	@echo ""
	@echo "  make up DEMO=symfony8            Start a demo (default: symfony8)"
	@echo "  make down DEMO=symfony8          Stop a demo"
	@echo "  make update-bundle DEMO=symfony8 Refresh bundle in a demo"
	@echo "  make up-symfony8                 Start Symfony 8 demo"
	@echo "  make down-symfony8               Stop Symfony 8 demo"
	@echo "  make test-symfony8               Run Symfony 8 demo tests"
	@echo "  make verify-symfony8             Ensure up and require HTTP 200"
	@echo "  make test-e2e-symfony8           Playwright e2e against the running demo (REQ-DEMO-013)"
	@echo "  make release-check               test-all + test-e2e-all (stack up) + release-verify"
	@echo ""

# REQ-MAKE-003: aggregate targets expected on demo/Makefile
DEMO ?= symfony8

up:
	@$(MAKE) -C $(DEMO) up

down:
	@$(MAKE) -C $(DEMO) down

update-bundle:
	@$(MAKE) -C $(DEMO) update-bundle

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony8:
	@$(MAKE) -C symfony8 test

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

verify-symfony8:
	@$(MAKE) -C symfony8 verify

# REQ-DEMO-013: Playwright e2e needs the stack up (the demo's test-e2e runs ensure-up first).
test-e2e-symfony8:
	@$(MAKE) -C symfony8 test-e2e

# REQ-DEMO-007: update-bundle runs via each demo's test target before composer test
test-all: test-symfony8

release-verify:
	@$(MAKE) up-symfony8
	@sleep 10
	@PORT=$$(grep '^PORT=' symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep '^PORT=' symfony8/.env.example | cut -d= -f2 | tr -d '\r'); \
	code=$$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT 2>/dev/null || echo "000"); \
	js=$$(curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT/build/entrypoints.json 2>/dev/null || echo "000"); \
	$(MAKE) down-symfony8; \
	if [ "$$code" != "200" ]; then echo "Demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
	if [ "$$js" != "200" ]; then echo "Demo Vite asset healthcheck failed (HTTP $$js)"; exit 1; fi; \
	echo "Symfony 8 demo OK (HTTP $$code, Vite entrypoints HTTP $$js)"

test-e2e-all: test-e2e-symfony8

# Sequential (safe under make -j): PHPUnit (stack up) → Playwright e2e while the stack is still up
# (REQ-DEMO-013) → release-verify (fresh up → HTTP 200 → down, REQ-TEST-011).
release-check:
	@$(MAKE) test-all
	@$(MAKE) test-e2e-all
	@$(MAKE) release-verify
	@echo "All demo checks passed."

clean:
	rm -rf symfony8/vendor symfony8/var symfony8/.phpunit.cache symfony8/node_modules symfony8/public/build

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# REQ-MAKE-008: update-deps-all
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
