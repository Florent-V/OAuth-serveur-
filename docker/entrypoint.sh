#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ]; then
    # Clés de signature des jetons : générées au premier démarrage, conservées dans un volume
    if [ ! -f config/jwt/private.pem ]; then
        echo "Génération de la paire de clés OAuth2..."
        php bin/console league:oauth2-server:generate-keypair --skip-if-exists
    fi
    mkdir -p public/uploads/logos
    chown -R www-data:www-data config/jwt var public/uploads

    echo "Attente de la base de données..."
    until php bin/console dbal:run-sql -q "SELECT 1" > /dev/null 2>&1; do
        sleep 2
    done

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console cache:warmup
    chown -R www-data:www-data var
fi

exec docker-php-entrypoint "$@"
