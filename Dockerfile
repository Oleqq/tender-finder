FROM node:20-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY tsconfig.json vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM node:20-alpine AS frontend-dev

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci \
    && sha256sum package-lock.json | awk '{print $1}' > node_modules/.tender-finder-package-lock.sha256

FROM frontend-dev AS vite-dev

COPY deploy/vite-dev-entrypoint.sh /usr/local/bin/tender-vite-dev-entrypoint
RUN chmod +x /usr/local/bin/tender-vite-dev-entrypoint

ENTRYPOINT ["tender-vite-dev-entrypoint"]
CMD ["npm", "run", "dev", "--", "--host=0.0.0.0"]

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

FROM composer:2 AS vendor-dev

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && sha256sum composer.lock | awk '{print $1}' > vendor/.tender-finder-composer-lock.sha256

FROM php:8.3-cli-alpine AS app-base

RUN apk add --no-cache $PHPIZE_DEPS ca-certificates curl libzip-dev libxml2-dev oniguruma-dev openssl postgresql-dev \
    && docker-php-ext-install mbstring opcache pcntl pdo_pgsql xml zip \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/html
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM app-base AS app

COPY --from=vendor /app/vendor ./vendor
COPY deploy/entrypoint.sh /usr/local/bin/tender-entrypoint

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi \
    && chmod +x /usr/local/bin/tender-entrypoint \
    && chown -R www-data:www-data vendor

USER www-data
EXPOSE 8080
ENTRYPOINT ["tender-entrypoint"]
# Railway injects PORT for the public web service. Docker Compose keeps using
# its explicit 8080 command, while this default also works outside Compose.
CMD ["sh", "-c", "exec php artisan serve --host=0.0.0.0 --port=\"${PORT:-8080}\" --no-reload"]

FROM app-base AS app-dev

COPY --from=vendor-dev --chown=www-data:www-data /app/vendor ./vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY deploy/dev-entrypoint.sh /usr/local/bin/tender-dev-entrypoint

RUN apk add --no-cache su-exec \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi \
    && chmod +x /usr/local/bin/tender-dev-entrypoint \
    && chown -R www-data:www-data vendor

EXPOSE 8080
ENTRYPOINT ["tender-dev-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]

# Keep the production-like image as Dockerfile's default target. The local
# development overlay explicitly selects app-dev.
FROM app AS production
