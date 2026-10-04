#!/bin/bash
set -e
mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
touch database/database.sqlite
php artisan config:clear
php artisan migrate:fresh --seed --force
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
