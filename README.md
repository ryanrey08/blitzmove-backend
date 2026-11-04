# BlitzMove API

Laravel 13 REST API for BlitzMove (PHP 8.3, Sanctum, MySQL 8.4). See the root README for architecture, business rules and the full-stack setup.

```bash
composer install
cp .env.example .env && php artisan key:generate
DEFAULT_ADMIN_PASSWORD='choose-one' php artisan migrate --seed
php artisan serve
php artisan test
```

Docker (php-fpm + nginx + MySQL + Redis + phpMyAdmin):

```bash
docker compose up -d --build        # creates .env from .env.example and an APP_KEY on first start
docker compose exec app php artisan migrate --seed
```

API: http://localhost:8100 · phpMyAdmin: http://localhost:8180 · MySQL: 127.0.0.1:3307 (blitzmove / secret) · Redis: 6380. These host ports avoid clashing with other local projects on 8000/8080/3306/6379; override them with APP_PORT, PHPMYADMIN_PORT, FORWARD_DB_PORT and FORWARD_REDIS_PORT in `.env`. The project folder is mounted into the containers, so code changes apply without a rebuild.

## Users, roles and permissions (RBAC)

Native Laravel RBAC, no package: `roles`, `permissions`, `permission_role`, and one `role_id` per user (`app/Models/Role.php`, `Permission.php`, `User::hasPermission()`). The permission catalogue (`module.action`) and the 8 initial roles live in `config/rbac.php`. Every API route declares `permission:<slug>` (`app/Http/Middleware/EnsureUserHasPermission.php`; `a|b` = any of). `Gate::before` also maps `$user->can('orders.update')`. Super Admin always has every permission. Escalation and lock-out rules are in `app/Services/AccessControl.php`. Inactive or suspended users can't sign in, and their tokens are revoked (`EnsureUserIsActive`).

The RBAC migration seeds roles and permissions itself and maps the old `users.role` values: admin becomes super-admin, manager becomes administrator, and dispatcher and viewer keep their names. So `php artisan migrate` is enough on an existing database.

```bash
php artisan migrate                                   # tables + roles/permissions
php artisan db:seed                                   # Permission, Role, RolePermission, DefaultAdmin, (demo data), DevelopmentUser
php artisan db:seed --class=RbacSeeder                # re-sync the permission catalogue after editing config/rbac.php
SEED_DEV_USERS=true DEV_USERS_PASSWORD='...' php artisan db:seed --class=DevelopmentUserSeeder   # sample account per role
```

Seeders are idempotent: existing users and passwords are never overwritten, and role permissions you edit in the app are kept on re-runs (only Super Admin is re-synced to "all"). Super Admin credentials come from `DEFAULT_ADMIN_*` (`SEED_ADMIN_*` still works). In production a missing password creates no account. Sample accounts are flagged `is_sample` and are refused in production unless `ALLOW_DEV_USERS_IN_PRODUCTION=true`.

Key code: `app/Services` (TripService, OrderService, ItineraryService, DocumentService, Routing/), `app/Enums` (status transitions), `routes/api.php`, `tests/Feature`.
