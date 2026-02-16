#!/usr/bin/env sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan key:generate --force --no-interaction || true
php artisan storage:link || true

if [ "${RUN_MIGRATIONS}" = "true" ]; then
  php artisan migrate --force --no-interaction || true
fi

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

exec "$@"
