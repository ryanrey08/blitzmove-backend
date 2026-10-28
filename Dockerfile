# BlitzMove API - PHP 8.3 (FPM + Apache) serving Laravel's public/ directory.
# serversideup/php ships pdo_mysql, zip, opcache and Composer, so no OS packages are compiled here.
FROM serversideup/php:8.3-fpm-apache

ENV PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=12M \
    PHP_POST_MAX_SIZE=14M \
    PHP_MEMORY_LIMIT=256M \
    SSL_MODE=off

WORKDIR /var/www/html

# Dependencies first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache

# Runs before the web server starts (see serversideup/php entrypoint.d)
COPY --chmod=755 docker/50-blitzmove-init.sh /etc/entrypoint.d/50-blitzmove-init.sh

EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 CMD curl -fsS http://localhost:8080/up || exit 1
