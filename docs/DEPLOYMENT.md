# Deployment

FreshMart is a Laravel 12 application with a Vue static bundle and SQLite. A
single writable application host is the supported topology. Shared,
multi-writer SQLite storage is not supported.

## Prepare

Install PHP 8.2+ with PDO SQLite, Composer 2, Node.js 22, and npm. Configure a
dedicated operating-system user. The web server should expose only
`apps/api/public`; it must not expose `.env`, `storage`, the database, backup
manifests, source files, or the preserved legacy database.

Production configuration must include:

- `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, and `APP_VERSION`;
- the public API URL and trusted frontend/Sanctum origins;
- an absolute modern `DB_DATABASE` path with foreign keys enabled;
- `SESSION_SECURE_COOKIE=true`, the production session domain, and an HTTPS
  `APP_URL`;
- deployment-specific administrator credentials if seeding is intentional;
- `FRESHMART_SEED_DEMO=false`; and
- appropriate log, cache, session, and queue drivers.

## Release procedure

1. Put the application into a maintenance window when schema changes are
   included.
2. Run `php artisan freshmart:backup` and retain the manifest.
3. Install PHP dependencies with
   `composer install --no-dev --optimize-autoloader --no-interaction`.
4. Install/build the frontend with `npm ci` and `npm run build`.
5. Run `php artisan migrate --force`.
6. Run `php artisan optimize` if configuration is final for that release.
7. Run `php artisan freshmart:health`.
8. Serve `apps/api/public/app/index.html` for the Vue client and
   `apps/api/public/index.php` for Laravel/API requests.
9. Smoke-test authentication, role landing pages, a read-only report, and one
   permitted operational workflow.

Terminate HTTPS at a maintained reverse proxy, redirect HTTP to HTTPS, forward
the original scheme/host/client address, and trust forwarded headers only from
the known proxy network. Preserve `X-Request-ID` when supplied and pass the
response header back to clients. Restrict request-body and upload limits to the
application’s actual needs.

Do not run `migrate:fresh`, demo seeders, or restore during a normal release.
Do not deploy with default/local passwords.

## Rollback

Prefer an application rollback when the schema is backward-compatible. If a
data restore is required, stop every writer and follow
[BACKUP_AND_RESTORE.md](BACKUP_AND_RESTORE.md). Migration rollback should only
be used when the release migration explicitly supports it and the impact has
been tested.
