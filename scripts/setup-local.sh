#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="$ROOT/apps/api"
WEB="$ROOT/apps/web"
SEED_DATABASE=false

if [ "${1:-}" = "--seed" ]; then
  SEED_DATABASE=true
elif [ -n "${1:-}" ]; then
  echo "Unknown option: $1"
  echo "Usage: bash scripts/setup-local.sh [--seed]"
  exit 2
fi

mkdir -p "$API/bootstrap/cache" \
         "$API/database" \
         "$API/storage/framework/cache/data" \
         "$API/storage/framework/sessions" \
         "$API/storage/framework/views" \
         "$API/storage/logs"
chmod -R u+rwX "$API/bootstrap" "$API/database" "$API/storage"

if [ ! -f "$API/.env" ]; then
  cp "$API/.env.example" "$API/.env"
  echo "Created apps/api/.env from .env.example."
else
  echo "Using existing apps/api/.env."
fi

cd "$API"
composer install
if ! grep -Eq '^APP_KEY=base64:.+' "$API/.env"; then
  php artisan key:generate
  echo "Generated a new application key because none was configured."
else
  echo "Kept the existing application key."
fi

DB_CONNECTION_VALUE="$(sed -n 's/^DB_CONNECTION=//p' "$API/.env" | tail -n 1 | tr -d '\"' | tr -d "'")"
DB_CONNECTION_VALUE="${DB_CONNECTION_VALUE:-sqlite}"
if [ "$DB_CONNECTION_VALUE" != "sqlite" ]; then
  echo "This setup helper only creates local SQLite databases."
  echo "Configured connection: $DB_CONNECTION_VALUE"
  exit 1
fi

DB_DATABASE_VALUE="$(sed -n 's/^DB_DATABASE=//p' "$API/.env" | tail -n 1 | tr -d '\"' | tr -d "'")"
DB_DATABASE_VALUE="${DB_DATABASE_VALUE:-database/database.sqlite}"
case "$DB_DATABASE_VALUE" in
  /*) DATABASE_PATH="$DB_DATABASE_VALUE" ;;
  *) DATABASE_PATH="$API/$DB_DATABASE_VALUE" ;;
esac

LEGACY_DATABASE_PATH="$ROOT/database/freshmart.sqlite"
mkdir -p "$(dirname "$DATABASE_PATH")"
if [ "$(cd "$(dirname "$DATABASE_PATH")" 2>/dev/null && pwd)/$(basename "$DATABASE_PATH")" = "$LEGACY_DATABASE_PATH" ]; then
  echo "Setup stopped: apps/api/.env points to the preserved legacy database:"
  echo "  $LEGACY_DATABASE_PATH"
  echo "Set DB_DATABASE=database/database.sqlite, then run setup again."
  exit 1
fi

if [ ! -f "$DATABASE_PATH" ]; then
  touch "$DATABASE_PATH"
  echo "Created SQLite database: $DATABASE_PATH"
else
  echo "Using existing SQLite database: $DATABASE_PATH"
fi

php artisan config:clear
CACHE_STORE=file php artisan cache:clear
php artisan migrate --force

if [ "$SEED_DATABASE" = true ] || [ "${FRESHMART_SEED_DATABASE:-0}" = "1" ]; then
  php artisan db:seed --force
  echo "Seeded roles, local accounts, settings, and configured demo data."
else
  echo "Skipped seed data. Re-run with --seed when demo/local data is wanted."
fi

if [ ! -f "$WEB/.env" ] && [ -f "$WEB/.env.example" ]; then
  cp "$WEB/.env.example" "$WEB/.env"
fi

cd "$WEB"
npm install

echo
echo "Setup complete. Run: bash scripts/start-local.sh"
