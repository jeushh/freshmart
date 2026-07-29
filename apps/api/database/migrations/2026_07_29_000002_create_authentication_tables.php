<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                full_name TEXT NOT NULL,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                employee_id INTEGER UNIQUE REFERENCES employees(id),
                status TEXT NOT NULL DEFAULT 'Active'
                    CHECK (status IN ('Active', 'Disabled')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_login TEXT
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                email TEXT PRIMARY KEY,
                token TEXT NOT NULL,
                created_at TEXT
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER,
                ip_address TEXT,
                user_agent TEXT,
                payload TEXT NOT NULL,
                last_activity INTEGER NOT NULL
            )
        SQL);
        DB::statement('CREATE INDEX idx_sessions_user_id ON sessions(user_id)');
        DB::statement('CREATE INDEX idx_sessions_last_activity ON sessions(last_activity)');

        DB::statement(<<<'SQL'
            CREATE TABLE personal_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tokenable_type TEXT NOT NULL,
                tokenable_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                token TEXT NOT NULL UNIQUE,
                abilities TEXT,
                last_used_at TEXT,
                expires_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_personal_access_tokens_tokenable '
            .'ON personal_access_tokens(tokenable_type, tokenable_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('admin_users');
    }
};
