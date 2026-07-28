# Local development

1. Install PHP 8.2+, Composer, Node.js 22+, and npm.
2. Copy `apps/api/.env.example` to `apps/api/.env`.
3. Copy `apps/web/.env.example` to `apps/web/.env`.
4. Run Composer and npm installation.
5. Generate the Laravel application key.
6. Start both local servers.

The database path is outside the API application so backend source updates do not overwrite business data.
