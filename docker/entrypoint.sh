#!/bin/sh
# Prepara o projeto montado em /var/www/html antes de iniciar o processo do container.
set -e
cd /var/www/html

como_app() { runuser -u www-data -- "$@"; }

if [ -f composer.json ] && [ ! -d vendor ]; then
    como_app composer install --no-interaction
fi

if [ -f artisan ] && [ "${PREPARAR_APP:-0}" = "1" ]; then
    [ -f .env ] || como_app cp .env.example .env
    grep -q '^APP_KEY=.\+' .env || como_app php artisan key:generate --force
    como_app php artisan migrate --force
fi

exec docker-php-entrypoint "$@"
