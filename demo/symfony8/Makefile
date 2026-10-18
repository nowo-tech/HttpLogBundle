.PHONY: help up down build install shell logs test test-coverage cache-clear link-bundle update update-bundle update-deps ensure-up restart release-check test-e2e demo-screenshots

DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
DOCKER_COMPOSE := docker-compose
else
DOCKER_COMPOSE := $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
SERVICE_PHP := php

help:
	@echo "Symfony 8 Demo - Http Log Bundle"
	@echo "  make test-e2e          Playwright e2e of the admin pages (REQ-DEMO-013)"
	@echo "  make demo-screenshots  Refresh docs/images/demo/admin-*.webp via Playwright"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@if ! $(DOCKER_COMPOSE) images -q $(SERVICE_PHP) 2>/dev/null | grep -q .; then \
		$(DOCKER_COMPOSE) up -d --build; \
	else \
		$(DOCKER_COMPOSE) up -d; \
	fi
	@echo "Waiting for container to be ready..."
	@i=0; \
	until $(DOCKER_COMPOSE) exec -T php true 2>/dev/null; do \
		i=$$((i+1)); \
		if [ $$i -ge 30 ]; then \
			echo "Container failed to become ready; last logs:"; \
			$(DOCKER_COMPOSE) logs --tail 80 php || true; \
			exit 1; \
		fi; \
		sleep 2; \
	done
	@echo "Installing dependencies..."
	@$(DOCKER_COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@$(MAKE) link-bundle
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(DOCKER_COMPOSE) down

restart: ensure-up
	$(DOCKER_COMPOSE) restart

build:
	$(DOCKER_COMPOSE) build --no-cache

ensure-up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@if ! $(DOCKER_COMPOSE) exec -T php true 2>/dev/null; then $(DOCKER_COMPOSE) up -d; sleep 3; fi

install: ensure-up
	$(DOCKER_COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(DOCKER_COMPOSE) exec php sh

logs:
	$(DOCKER_COMPOSE) logs -f

test: ensure-up link-bundle
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php sh -c "mkdir -p var/data && php bin/console doctrine:schema:update --force"
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up link-bundle
	$(DOCKER_COMPOSE) exec -e APP_ENV=test php composer test-coverage

cache-clear: ensure-up
	$(DOCKER_COMPOSE) exec php php bin/console cache:clear

link-bundle: ensure-up
	@echo "Waiting for MySQL from PHP container..."
	@i=0; \
	while [ $$i -lt 30 ]; do \
		if $(DOCKER_COMPOSE) exec php sh -c "mkdir -p var/data && rm -rf vendor/nowo-tech/http-log-bundle && ln -sf /var/http-log-bundle vendor/nowo-tech/http-log-bundle && php bin/console doctrine:schema:update --force --complete && php bin/console cache:clear" 2>/dev/null; then \
			break; \
		fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	if [ $$i -ge 30 ]; then \
		echo "MySQL not reachable from demo PHP container"; \
		$(DOCKER_COMPOSE) logs --tail=50 database php || true; \
		exit 1; \
	fi

update: ensure-up
	$(DOCKER_COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer update --no-interaction

update-bundle: ensure-up
	$(DOCKER_COMPOSE) exec php composer update nowo-tech/http-log-bundle --no-interaction
	@$(MAKE) link-bundle

release-check: ensure-up update-bundle test test-coverage

# Optional: monorepo helper absent on standalone GitHub Actions checkout.
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)

# REQ-DEMO-013: Playwright e2e + WebP screenshots (official Playwright image). The specs log their own traffic
# through /api/* (async: false) and sign in to /admin/http-log with the demo's HTTP Basic user (admin / admin).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8091}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8091}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"
