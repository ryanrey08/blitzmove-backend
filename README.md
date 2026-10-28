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

API: http://localhost:8000 · phpMyAdmin: http://localhost:8080 · MySQL: 127.0.0.1:3306 (blitzmove / secret) · Redis: 6379. The project folder is mounted into the containers, so code changes apply without a rebuild.

Key code: `app/Services` (TripService, OrderService, ItineraryService, DocumentService, Routing/), `app/Enums` (status transitions), `routes/api.php`, `tests/Feature`.
