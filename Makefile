SHELL := /bin/sh

COMPOSE := docker compose -f compose.local.yml -f compose.local.dev.yml
ENV_FILE := deploy/local-runtime.env
SERVICES := web queue scheduler vite

.DEFAULT_GOAL := help

.PHONY: help doctor build up down restart ps logs migrate test lint analyse frontend-check check shell

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target>\n\nTargets:\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Check local prerequisites and environment configuration
	@command -v docker >/dev/null 2>&1 || { echo "Error: Docker is not installed or is not in PATH." >&2; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo "Error: Docker Compose is unavailable." >&2; exit 1; }
	@test -f $(ENV_FILE) || { echo "Error: $(ENV_FILE) is missing. Copy deploy/local-runtime.example.env first." >&2; exit 1; }
	@key="$$(sed -n 's/^APP_KEY=//p' $(ENV_FILE))"; \
	case "$$key" in \
		base64:*) encoded="$${key#base64:}" ;; \
		*) echo "Error: APP_KEY must start with base64:." >&2; exit 1 ;; \
	esac; \
	printf '%s' "$$encoded" | base64 -d >/dev/null 2>&1 || { echo "Error: APP_KEY contains invalid Base64." >&2; exit 1; }; \
	decoded_bytes="$$(printf '%s' "$$encoded" | base64 -d 2>/dev/null | wc -c | tr -d ' ')"; \
	[ "$$decoded_bytes" = "32" ] || { echo "Error: APP_KEY must contain a Base64-encoded 32-byte key." >&2; exit 1; }
	@docker info >/dev/null 2>&1 || { echo "Error: Docker daemon is not running or is not accessible." >&2; exit 1; }
	@$(COMPOSE) config --quiet
	@echo "Local environment looks valid."

build: ## Build local development images
	$(COMPOSE) build

up: ## Start the local development services
	$(COMPOSE) up -d $(SERVICES)

down: ## Stop services without deleting persistent volumes
	$(COMPOSE) down

restart: ## Recreate the application services
	$(COMPOSE) up -d --force-recreate $(SERVICES)

ps: ## Show service status
	$(COMPOSE) ps

logs: ## Follow application and Vite logs
	$(COMPOSE) logs -f $(SERVICES)

migrate: ## Run database migrations
	$(COMPOSE) --profile ops run --rm migrate

test: ## Run the Laravel test suite
	$(COMPOSE) exec -T web php artisan test

lint: ## Check PHP formatting
	$(COMPOSE) exec -T web vendor/bin/pint --test

analyse: ## Run PHPStan
	$(COMPOSE) exec -T web vendor/bin/phpstan analyse --memory-limit=1G

frontend-check: ## Build and lint the frontend
	$(COMPOSE) exec -T vite npm run build
	$(COMPOSE) exec -T vite npm run lint
	$(COMPOSE) exec -T vite npm run format:check

check: test lint analyse frontend-check ## Run all project checks

shell: ## Open a shell in the web container
	$(COMPOSE) exec web sh
