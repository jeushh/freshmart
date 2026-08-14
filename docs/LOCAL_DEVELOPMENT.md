# Local development

1. Install PHP 8.2+, Composer, Node.js 22+, and npm.
2. Run `bash scripts/setup-local.sh --seed` on macOS/Linux or
   `scripts\setup-windows.bat --seed` on Windows.
3. Start both local servers with the matching start script.

The modern database is `apps/api/database/database.sqlite`. It is ignored by
Git and is created only when missing. The setup scripts preserve an existing
application key and seed only when `--seed` (or
`FRESHMART_SEED_DATABASE=1`) is supplied.

The committed `database/freshmart.sqlite` file is a legacy/reference database.
The setup scripts refuse to run Laravel migrations against it.

To prepare dependencies and migrations without demo data, omit `--seed`.
To reset a disposable local database later, first confirm the database path,
then run `php artisan migrate:fresh --seed` from `apps/api`.

## Verification

Run the same core checks used by continuous integration:

```bash
cd apps/api
composer validate --strict --no-check-publish
./vendor/bin/pint --test
php artisan migrate:status
php artisan freshmart:health
php artisan test

cd ../web
npm run lint
npm run build
```

The frontend build is written to `apps/api/public/app`. Open it through the
configured web server in deployment; the development server remains the
recommended local workflow.

## Local authentication and Safari

`localhost` and `127.0.0.1` resolve to the same computer, but browsers treat
them as different origins and different cookie sites. A frontend opened at
`http://localhost:5173` cannot safely assume that cookies from
`http://127.0.0.1:8000` behave as first-party cookies.

FreshMart handles this in two layers:

- Laravel explicitly allows both local frontend origins for credentialed CORS
  requests and treats both host forms as Sanctum stateful domains.
- During `npm run dev`, Vite proxies `/api` and `/sanctum` to
  `VITE_API_URL`. The browser therefore sees first-party requests, which
  avoids Safari third-party-cookie restrictions when the host names differ.

Use these local values in `apps/api/.env`:

```dotenv
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost,127.0.0.1
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
```

Use this target in `apps/web/.env`:

```dotenv
VITE_API_URL=http://127.0.0.1:8000
```

After changing environment values, clear cached Laravel configuration and
restart both servers:

```bash
cd apps/api
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=8000
```

In another terminal:

```bash
cd apps/web
npm run dev -- --host 127.0.0.1
```

The localhost alternative is:

```bash
cd apps/api
php artisan optimize:clear
php artisan serve --host=localhost --port=8000
```

In another terminal:

```bash
cd apps/web
npm run dev -- --host localhost
```

Both `http://127.0.0.1:5173` and `http://localhost:5173` are supported while
the configured API target is either loopback host. Keep the API and Vite
processes running in separate terminals. The application and SQLite database
remain fully local, so this flow works without an internet connection once
Composer and npm dependencies are installed.

If Safari still uses an old session:

1. sign out and close every FreshMart tab;
2. open Safari Settings, then Privacy, then Manage Website Data;
3. remove entries for both `localhost` and `127.0.0.1`;
4. restart the API and Vite servers; and
5. reload the login page and sign in again.

When switching host forms, clear both sites rather than only the URL currently
visible in the address bar. Also run `php artisan optimize:clear` after every
CORS, Sanctum, session, or `.env` change.

Production must set `CORS_ALLOWED_ORIGINS` and
`SANCTUM_STATEFUL_DOMAINS` to explicit trusted deployment hosts. Never use a
wildcard origin with credentialed requests. Use HTTPS with
`SESSION_SECURE_COOKIE=true`.

## Local backups

`php artisan freshmart:backup` creates live-safe SQLite snapshots under
`apps/api/storage/app/backups`. Do not use the restore command while either
local server is running. See [BACKUP_AND_RESTORE.md](BACKUP_AND_RESTORE.md).
