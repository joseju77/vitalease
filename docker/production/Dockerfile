# Production images. Build from the repository root:
#   docker build --target app   -f docker/production/Dockerfile -t vitalease/app:TAG .
#   docker build --target nginx -f docker/production/Dockerfile -t vitalease/nginx:TAG .
#   docker build --target seeder -f docker/production/Dockerfile -t vitalease/seeder:TAG .
# The same app image runs the app, worker, scheduler and one-off job roles via `command`.
# The seeder image is used only by the demo-seed job (it needs Faker, a dev dependency).

# ---------------------------------------------------------------------------
# base: PHP runtime with the extensions the application needs (no xdebug).
# ---------------------------------------------------------------------------
FROM php:8.5.10-fpm-alpine3.24 AS base

RUN apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      icu-dev \
      libpq-dev \
      libzip-dev \
      linux-headers \
    && apk add --no-cache \
      icu-libs \
      libpq \
      libzip \
      fcgi \
      tini \
    && pecl install -o -f redis \
    && docker-php-ext-install \
      intl \
      pdo_pgsql \
      zip \
      pcntl \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# ---------------------------------------------------------------------------
# vendor: production composer dependencies and optimized autoloader.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# vendor-dev: the same tree plus dev composer dependencies, for the seeder image.
# package:discover is not re-run, so the discovered providers stay the production
# set and dev-only service providers are never registered.
# ---------------------------------------------------------------------------
FROM vendor AS vendor-dev

RUN composer install --no-scripts --no-autoloader --prefer-dist \
    && composer dump-autoload --optimize

# ---------------------------------------------------------------------------
# assets: Vite build. Wayfinder runs `php artisan wayfinder:generate` during the
# build, so this stage needs PHP and vendor besides node. Node and npm are pinned
# to the exact apk versions available in alpine3.24 (as the dev image uses).
# ---------------------------------------------------------------------------
FROM vendor AS assets

RUN apk add --no-cache nodejs=24.22.1-r0 npm=11.12.1-r0

WORKDIR /var/www/html

COPY package.json package-lock.json .npmrc ./
RUN npm ci

# Wayfinder boots the framework; a throwaway key is enough, nothing is persisted
# (set inline so it never becomes image metadata). opcache is built into PHP 8.5.
RUN APP_KEY="base64:$(head -c 32 /dev/zero | base64)" APP_ENV=production npm run build

# ---------------------------------------------------------------------------
# app: final php-fpm image. No node, sshd, supervisord, composer or xdebug.
# ---------------------------------------------------------------------------
FROM base AS app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/production/php/php.ini "$PHP_INI_DIR/conf.d/zz-production.ini"
COPY docker/production/php/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
COPY docker/production/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build

COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/public storage/app/private storage/logs \
       storage/framework/cache/data storage/framework/sessions storage/framework/views \
       bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f .env

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER www-data

EXPOSE 9000

ENTRYPOINT ["tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# seeder: the app image with dev dependencies, only for `demo:seed` one-off jobs.
# Never deployed as a service.
# ---------------------------------------------------------------------------
FROM app AS seeder

COPY --from=vendor-dev --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

# ---------------------------------------------------------------------------
# nginx: serves the baked public/ directory and proxies PHP to app:9000.
# ---------------------------------------------------------------------------
FROM nginx:1.31.4-alpine3.24 AS nginx

COPY docker/production/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /var/www/html/public /var/www/html/public
COPY --from=assets /var/www/html/public/build /var/www/html/public/build

# Never serve PHP sources from the static copy.
RUN rm -f /var/www/html/public/index.php \
    && printf '<?php\n' > /var/www/html/public/index.php
