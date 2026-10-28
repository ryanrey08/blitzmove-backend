#!/bin/sh
# BlitzMove container start-up: wait for MySQL, then optionally migrate and seed.
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "[blitzmove] APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
  exit 1
fi

if [ "$DB_CONNECTION" = "mysql" ]; then
  echo "[blitzmove] Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
  i=0
  until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
      echo "[blitzmove] MySQL did not become reachable." >&2
      exit 1
    fi
    sleep 2
  done
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  php artisan db:seed --force
fi

php artisan config:clear >/dev/null
