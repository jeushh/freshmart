<?php

namespace App\Console\Commands;

use App\Services\SQLiteBackupService;
use Illuminate\Console\Command;

class BackupFreshMart extends Command
{
    protected $signature = 'freshmart:backup
        {--database= : SQLite database path}
        {--directory= : Destination directory}
        {--retention= : Number of normal backups to retain}';

    protected $description = 'Create a validated SQLite backup and checksum manifest';

    public function handle(SQLiteBackupService $backups): int
    {
        try {
            $retention = $this->option('retention');
            $result = $backups->create(
                $this->option('database') ?: null,
                $this->option('directory') ?: null,
                $retention !== null ? (int) $retention : null,
            );
            $this->info('Backup created: '.$result['backup']);
            $this->line('Manifest: '.$result['manifest']);
            $this->line('SHA-256: '.$result['sha256']);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
