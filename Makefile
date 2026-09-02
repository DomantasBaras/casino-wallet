.PHONY: up down build sh logs artisan migrate fresh test health

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

sh:
	docker compose exec app sh

logs:
	docker compose logs -f app nginx

# usage: make artisan CMD="make:model Wallet -m"
artisan:
	docker compose exec app php artisan $(CMD)

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test

health:
	curl -i http://localhost:8080/api/health
