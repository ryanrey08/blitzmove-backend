#!/bin/sh
# Rebuilds Laravel's caches from the container environment on every start, then runs the command.
set -e
cd /var/www
php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
