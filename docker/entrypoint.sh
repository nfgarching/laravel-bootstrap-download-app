#!/bin/sh
set -e

cd /app

# Verzeichnisse, die in frischen Volumes fehlen.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/vault \
    database/data

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -n "${DB_DATABASE}" ] && [ ! -f "${DB_DATABASE}" ]; then
    touch "${DB_DATABASE}"
fi

# Migrationen laufen nur im App-Container, nicht im Scheduler.
if [ "${PORTAL_RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
