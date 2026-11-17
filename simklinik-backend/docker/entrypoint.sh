#!/bin/sh
set -e

cd /var/www/html

if [ ! -d "vendor" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f ".env" ]; then
    php artisan key:generate --force || true
    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan route:clear || true
    php artisan view:clear || true
    php artisan storage:link || true
fi

FRONTEND_ASSETS="/var/www/frontend/public/assets"
BACKEND_ASSETS="/var/www/html/public/assets"

if [ -d "$FRONTEND_ASSETS" ] && [ ! -e "$BACKEND_ASSETS" ]; then
    ln -sfn "$FRONTEND_ASSETS" "$BACKEND_ASSETS"
fi

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true

exec apache2-foreground