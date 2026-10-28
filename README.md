# BlitzMove API

Laravel 13 REST API for BlitzMove (PHP 8.3, Sanctum, MySQL 8.4). See the root README for architecture, business rules and the full-stack setup.

```bash
composer install
cp .env.example .env && php artisan key:generate
SEED_ADMIN_PASSWORD='choose-one' php artisan migrate --seed
php artisan serve
php artisan test
```

Docker (php-fpm + nginx + MySQL + Redis + phpMyAdmin):

```bash
docker compose up -d --build        # creates .env from .env.example and an APP_KEY on first start
docker compose exec app php artisan migrate --seed
```

API: http://localhost:8100 · phpMyAdmin: http://localhost:8180 · MySQL: 127.0.0.1:3307 (blitzmove / secret) · Redis: 6380. These host ports avoid clashing with other local projects on 8000/8080/3306/6379; override them with APP_PORT, PHPMYADMIN_PORT, FORWARD_DB_PORT and FORWARD_REDIS_PORT in `.env`. The project folder is mounted into the containers, so code changes apply without a rebuild.

Key code: `app/Services` (TripService, OrderService, ItineraryService, DocumentService, Routing/), `app/Enums` (status transitions), `routes/api.php`, `tests/Feature`.
