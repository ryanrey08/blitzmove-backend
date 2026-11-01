# BlitzMove API production images, built from the repository root:
#   docker build -f deploy/production/Dockerfile --target app -t blitzmove-app:TAG .
#   docker build -f deploy/production/Dockerfile --target web -t blitzmove-web:TAG .
# "app" is PHP-FPM with the code and production dependencies baked in; "web" is nginx serving public/.

FROM php:8.3-fpm AS app

RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY deploy/production/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY deploy/production/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN rm -rf deploy tests .env .env.* \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm /usr/bin/composer

COPY deploy/production/entrypoint.sh /usr/local/bin/blitzmove-entrypoint
RUN chmod 755 /usr/local/bin/blitzmove-entrypoint

ENTRYPOINT ["blitzmove-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY deploy/production/nginx/api.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/public /var/www/public
