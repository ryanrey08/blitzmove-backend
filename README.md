# BlitzMove API

Laravel 13 REST API for BlitzMove (PHP 8.3, Sanctum, MySQL 8.4). See the root README for architecture, business rules and the full-stack setup.

```bash
composer install
cp .env.example .env && php artisan key:generate
SEED_ADMIN_PASSWORD='choose-one' php artisan migrate --seed
php artisan serve
php artisan test
```

Docker (API + MySQL only): fill `.env` (APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD), then run `docker compose up --build`.

Key code: `app/Services` (TripService, OrderService, ItineraryService, DocumentService, Routing/), `app/Enums` (status transitions), `routes/api.php`, `tests/Feature`.
