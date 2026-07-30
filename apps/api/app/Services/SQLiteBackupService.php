<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SQLiteBackupService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    public function create(
        ?string $database = null,
        ?string $directory = null,
        ?int $retention = null,
        bool $isSafetyBackup = false,
    ): array {
        $database = $this->databasePath($database);
        $directory = $this->backupDirectory($directory);
        $this->assertSafeDatabase($database);
        $this->ensureDirectory($directory);
        $this->assertIntegrity($database);

        $prefix = $isSafetyBackup ? 'pre-restore' : 'freshmart';
        $timestamp = now('UTC')->format('Ymd-His-u');
        $backup = $directory.DIRECTORY_SEPARATOR.$prefix.'-'.$timestamp.'.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('VACUUM INTO '.$pdo->quote($backup));
        $this->assertIntegrity($backup);

        $manifest = [
            'format_version' => 1,
            'application' => 'FreshMart',
            'application_version' => (string) config('app.version', 'unknown'),
            'created_at' => now('UTC')->toIso8601String(),
            'database_filename' => basename($backup),
            'database_size' => filesize($backup),
            'sha256' => hash_file('sha256', $backup),
        ];
        $manifestPath = $this->manifestPath($backup);
        if (file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            LOCK_EX,
        ) === false) {
            throw new RuntimeException('The backup manifest could not be written.');
        }
        @chmod($backup, 0600);
        @chmod($manifestPath, 0600);
        $this->prune(
            $directory,
            $retention ?? (int) $this->settings->all()['backup_retention_count'],
            $isSafetyBackup ? 'pre-restore-' : 'freshmart-',
        );

        return ['backup' => $backup, 'manifest' => $manifestPath] + $manifest;
    }

    public function restore(
        string $backup,
        ?string $database = null,
        ?string $directory = null,
    ): array {
        $database = $this->databasePath($database);
        $directory = $this->backupDirectory($directory);
        $this->assertSafeDatabase($database);
        $backup = $this->resolveBackup($backup, $directory);
        if (realpath($database) === $backup) {
            throw new RuntimeException('The backup and target database must be different files.');
        }

        $manifest = $this->validateManifest($backup);
        $this->assertIntegrity($backup);
        $safety = $this->create($database, $directory, null, true);
        $temporary = $database.'.restore-'.bin2hex(random_bytes(6));

        try {
            if (! copy($backup, $temporary)) {
                throw new RuntimeException('The restored database could not be staged.');
            }
            @chmod($temporary, fileperms($database) & 0777);
            $this->assertIntegrity($temporary);
            DB::purge('sqlite');
            if (! rename($temporary, $database)) {
                throw new RuntimeException('The restored database could not replace the target.');
            }
            $this->assertIntegrity($database);
            Cache::flush();
            $this->settings->forget();
        } catch (\Throwable $exception) {
            @unlink($temporary);
            if (is_file($safety['backup'])) {
                copy($safety['backup'], $database);
            }
            throw new RuntimeException(
                'Restore failed; the pre-restore safety backup was reapplied. '.$exception->getMessage(),
                previous: $exception,
            );
        } finally {
            DB::purge('sqlite');
        }

        return [
            'restored_from' => $backup,
            'target' => $database,
            'sha256' => $manifest['sha256'],
            'safety_backup' => $safety['backup'],
        ];
    }

    public function assertIntegrity(string $database): void
    {
        if (! is_file($database) || ! is_readable($database)) {
            throw new RuntimeException("SQLite database is not readable: {$database}");
        }
        $pdo = new \PDO('sqlite:'.$database);
        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($result !== 'ok') {
            throw new RuntimeException("SQLite integrity check failed: {$result}");
        }
        if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
            throw new RuntimeException('SQLite foreign-key check failed.');
        }
    }

    public function databasePath(?string $database = null): string
    {
        return $this->absolutePath(
            $database ?: (string) config('database.connections.sqlite.database'),
        );
    }

    public function backupDirectory(?string $directory = null): string
    {
        return $this->absolutePath(
            $directory ?: storage_path('app/backups'),
        );
    }

    private function validateManifest(string $backup): array
    {
        $path = $this->manifestPath($backup);
        if (! is_file($path)) {
            throw new RuntimeException('The backup manifest is missing.');
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (
            ! is_array($manifest)
            || ($manifest['format_version'] ?? null) !== 1
            || ($manifest['database_filename'] ?? null) !== basename($backup)
            || ! is_string($manifest['sha256'] ?? null)
        ) {
            throw new RuntimeException('The backup manifest is invalid.');
        }
        if (! hash_equals($manifest['sha256'], hash_file('sha256', $backup))) {
            throw new RuntimeException('The backup checksum does not match its manifest.');
        }

        return $manifest;
    }

    private function resolveBackup(string $backup, string $directory): string
    {
        $candidate = str_contains($backup, DIRECTORY_SEPARATOR)
            ? $this->absolutePath($backup)
            : $directory.DIRECTORY_SEPARATOR.$backup;
        $resolved = realpath($candidate);
        $root = realpath($directory);
        if (
            $resolved === false
            || $root === false
            || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            || ! str_ends_with($resolved, '.sqlite')
        ) {
            throw new RuntimeException('The backup must be a .sqlite file inside the backup directory.');
        }

        return $resolved;
    }

    private function assertSafeDatabase(string $database): void
    {
        $legacy = $this->absolutePath(dirname(base_path(), 2).'/database/freshmart.sqlite');
        if ($database === $legacy || realpath($database) === realpath($legacy)) {
            throw new RuntimeException('The preserved legacy database cannot be backed up or restored by this command.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Backup directory could not be created: {$directory}");
        }
        if (! is_writable($directory)) {
            throw new RuntimeException("Backup directory is not writable: {$directory}");
        }
    }

    private function prune(string $directory, int $retention, string $prefix): void
    {
        $retention = max(1, min(100, $retention));
        $files = glob($directory.DIRECTORY_SEPARATOR.$prefix.'*.sqlite') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, $retention) as $file) {
            @unlink($file);
            @unlink($this->manifestPath($file));
        }
    }

    private function manifestPath(string $backup): string
    {
        return $backup.'.manifest.json';
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
