#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="$ROOT/apps/api"
WEB="$ROOT/apps/web"

mkdir -p "$API/bootstrap/cache" \
         "$API/storage/framework/cache/data" \
         "$API/storage/framework/sessions" \
         "$API/storage/framework/views" \
         "$API/storage/logs"
chmod -R u+rwX "$API/bootstrap" "$API/storage" "$ROOT/database"

if [ ! -f "$API/.env" ]; then
  cp "$API/.env.example" "$API/.env"
fi

cd "$API"
composer install
if ! grep -Eq '^APP_KEY=base64:.+' "$API/.env"; then
  php artisan key:generate
fi
php artisan config:clear
CACHE_STORE=file php artisan cache:clear

if [ ! -f "$WEB/.env" ] && [ -f "$WEB/.env.example" ]; then
  cp "$WEB/.env.example" "$WEB/.env"
fi

cd "$WEB"
npm install

echo
echo "Setup complete. Run: bash scripts/start-local.sh"
