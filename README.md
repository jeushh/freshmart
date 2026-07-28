# FreshMart Business Management System

A local-first business system using **Vue 3 + Vite** for the interface and **Laravel 12 + Sanctum + SQLite** for the API.

## Folder layout

- `apps/web` — Vue application
- `apps/api` — Laravel API
- `database` — local SQLite database and backups
- `docs` — architecture and migration notes
- `scripts` — local setup helpers
- `legacy` — preserved previous PHP system for reference and rollback

## Local requirements

- PHP 8.2+
- Composer
- Node.js 22+
- npm

## Start locally

Windows:

```bat
scripts\setup-windows.bat
scripts\start-windows.bat
```

macOS/Linux:

```bash
bash scripts/setup-local.sh
bash scripts/start-local.sh
```

Open `http://127.0.0.1:5173`.

No XAMPP is required. The Laravel API uses PHP's local development server.
