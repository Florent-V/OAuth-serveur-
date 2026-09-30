# Image de production : FrankenPHP (Caddy + PHP) écoute en HTTP sur le port 80.
# Placez-la derrière votre reverse proxy (Traefik, Nginx, Caddy...) qui gère le HTTPS
# pour oauth.mydomain.com.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql intl opcache zip

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=":80" \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN rm -f .env.local .env.*.local \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script --no-dev post-install-cmd \
    && mkdir -p var config/jwt && chown -R www-data:www-data var config/jwt

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
