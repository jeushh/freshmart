<?php
declare(strict_types=1);

/** Production-aware runtime configuration. Values can be supplied as environment variables. */
const APP_NAME = 'FreshMart Business System';
const APP_ENV = 'development';
const SESSION_NAME = 'freshmart_session';

function appEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function isProduction(): bool
{
    return strtolower((string) appEnv('APP_ENV', APP_ENV)) === 'production';
}

function databasePath(): string
{
    return (string) appEnv('DB_PATH', __DIR__ . '/freshmart.db');
}

function demoSeedingEnabled(): bool
{
    return filter_var(appEnv('SEED_DEMO_DATA', 'false'), FILTER_VALIDATE_BOOLEAN);
}


function sessionIdleTimeoutSeconds(): int
{
    return max(300, (int) appEnv('SESSION_IDLE_TIMEOUT', '1800'));
}

function backupDirectory(): string
{
    return (string) appEnv('BACKUP_DIR', dirname(databasePath()) . '/backups');
}
