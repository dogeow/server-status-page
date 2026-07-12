.PHONY: init build up down logs test owner agent-token agent-up migrate

OWNER_EMAIL ?= owner@example.com

init:
	./scripts/init-env.sh

build: init
	docker compose build

up: init
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f --tail=200

migrate:
	docker compose run --rm api php artisan migrate --force

owner:
	docker compose exec api php artisan status:bootstrap-owner $(OWNER_EMAIL)

agent-token:
	docker compose exec api php artisan status:agent-token central-agent

agent-up:
	docker compose --profile agent up -d --build central-agent

test:
	cd apps/web && npm test
	cd apps/api && php artisan test
	cd agent && go test -race ./...
	cd packages/laravel-probe && composer install --no-interaction && vendor/bin/phpunit
