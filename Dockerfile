# syntax=docker/dockerfile:1
#
# Image FrankenPHP (Caddy + PHP 8.4 ZTS) multi-étapes :
#   - frankenphp_dev  : développement (code monté en volume, Xdebug disponible, rechargement auto)
#   - frankenphp_prod : production (dépendances sans dev, autoload optimisé, mode worker, utilisateur non-root)
#
# Construction : make build (dev) / make prod-build (prod)

ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# Base commune
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS frankenphp_base

# UID/GID de l'utilisateur applicatif (en dev, alignés sur l'utilisateur de la machine hôte)
ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

# Extensions PHP : PostgreSQL, intl (formats de dates fr), APCu (cache système Symfony), zip (Composer)
RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        zip

# Utilisateur non-root ; FrankenPHP garde le droit d'écouter sur un port < 1024 si besoin
RUN groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid app --create-home --shell /bin/sh app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy /app/config/jwt /app/public/uploads/logos \
    # (les volumes nommés héritent de ces propriétaires à leur création)
    && chown -R app:app /data /config /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d" \
    # Port HTTP interne du conteneur (pas de HTTPS ici : il est géré par le Caddy de l'hôte)
    SERVER_NAME=":8080"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8080

HEALTHCHECK --start-period=60s --interval=30s --timeout=5s \
    CMD curl -fsS http://localhost:2019/metrics > /dev/null || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
# Développement
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_dev

# Pas de mode worker en dev : chaque requête relit le code, les modifications sont visibles immédiatement
ENV APP_ENV=dev \
    XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions xdebug

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

USER app

# --watch : recharge Caddy si le Caddyfile monté change
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# ---------------------------------------------------------------------------
# Production
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # Mode worker : l'application Symfony reste chargée en mémoire entre les requêtes
    FRANKENPHP_CONFIG="import worker.Caddyfile"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/worker.Caddyfile

# 1) Dépendances seules (couche mise en cache tant que composer.lock ne change pas)
COPY --link composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# 2) Code de l'application
COPY --link . ./
RUN rm -rf frankenphp deploy tests \
    && mkdir -p var/cache var/log var/share config/jwt public/uploads/logos \
    && composer dump-autoload --no-dev --classmap-authoritative \
    # Compile les .env en .env.local.php (plus rapide ; les vraies variables d'environnement restent prioritaires)
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console \
    && chown -R app:app var config/jwt public/uploads \
    && sync

USER app
