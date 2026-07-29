# FreshMart Business Management System

A local-first business system using **Vue 3 + Vite** for the interface and
**Laravel 12 + Sanctum + SQLite** for the API.

## Folder layout

- `apps/web` — Vue application
- `apps/api` — Laravel API, migrations, seeders, and its local SQLite file
- `database/freshmart.sqlite` — preserved legacy/reference database
- `docs` — architecture, local development, and schema notes
- `scripts` — local setup helpers
- `legacy` — preserved previous PHP system for reference and rollback

The modern Laravel database defaults to
`apps/api/database/database.sqlite`. The committed
`database/freshmart.sqlite` file is historical reference data; setup,
migrations, seeders, and tests do not write to it.

## Local requirements

- PHP 8.2+
- Composer
- Node.js 22+
- npm

## Fresh installation

macOS/Linux:

```bash
bash scripts/setup-local.sh --seed
bash scripts/start-local.sh
```

Windows:

```bat
scripts\setup-windows.bat --seed
scripts\start-windows.bat
```

The setup helper:

1. creates `.env` files only when they are missing;
2. keeps an existing Laravel application key;
3. creates the configured SQLite file only when it is missing;
4. refuses to migrate the preserved legacy database;
5. runs `php artisan migrate --force`; and
6. runs seeders only when `--seed` or `FRESHMART_SEED_DATABASE=1` is supplied.

Open `http://127.0.0.1:5173`. No XAMPP is required; the API uses PHP's local
development server.

## Migrations

From `apps/api`:

```bash
php artisan migrate
php artisan migrate:status
```

For an empty disposable SQLite database:

```bash
php artisan migrate:fresh --seed
```

`migrate:fresh` drops every table in the configured database. Never run it
against production data or `database/freshmart.sqlite`. Check `DB_DATABASE`
before using it.

## Seeders and local accounts

The seeders create:

- seven access-control roles and their permission sets;
- one environment-configured development System Administrator;
- core system settings; and
- optional demo employees, accounts, suppliers, products, attendance,
  requests, payroll, and opening inventory movements.

Seeder credentials come from:

- `FRESHMART_ADMIN_USERNAME`
- `FRESHMART_ADMIN_NAME`
- `FRESHMART_ADMIN_PASSWORD`
- `FRESHMART_SEED_DEMO`
- `FRESHMART_DEMO_PASSWORD`

The checked-in `.env.example` values are local-only defaults. Set
deployment-specific secrets before intentionally seeding outside local
development. Passwords are stored only as Laravel password hashes in SQLite.
Seeders reject missing credential values outside local/testing environments.

Run repeatable seeders explicitly:

```bash
cd apps/api
php artisan db:seed
```

## Resetting a disposable local database

First confirm `DB_DATABASE=database/database.sqlite` in `apps/api/.env`, then:

```bash
cd apps/api
php artisan migrate:fresh --seed
```

This is a destructive reset. Back up any data you need first.

## SQLite backups

Stop the API before copying a live local database, then use SQLite's backup
command so WAL data is included safely:

```bash
mkdir -p database/backups
sqlite3 apps/api/database/database.sqlite \
  ".backup 'database/backups/freshmart-$(date +%Y%m%d-%H%M%S).sqlite'"
```

Restore only into a stopped, disposable/local environment. Keep
`database/freshmart.sqlite` unchanged as the legacy reference database.

See [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) for the schema map and
documented differences from the legacy database.
