FROM node:20-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY tsconfig.json vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

FROM php:8.3-cli-alpine AS app

RUN apk add --no-cache curl libzip-dev libxml2-dev oniguruma-dev postgresql-dev \
    && docker-php-ext-install mbstring opcache pcntl pdo_pgsql xml zip \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY deploy/entrypoint.sh /usr/local/bin/tender-entrypoint

RUN chmod +x /usr/local/bin/tender-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8080
ENTRYPOINT ["tender-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
