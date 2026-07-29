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
