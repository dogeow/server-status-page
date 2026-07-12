FROM dunglas/frankenphp:1-php8.4-bookworm AS base

RUN install-php-extensions pdo_pgsql redis pcntl intl zip opcache
WORKDIR /app

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

FROM base AS runtime
ENV APP_ENV=production APP_DEBUG=false SERVER_NAME=:8000
COPY --from=build --chown=www-data:www-data /app /app
COPY Caddyfile /etc/caddy/Caddyfile
RUN chmod -R ug+rwX storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
