<?php

namespace App\Console\Commands;

use App\Services\SQLiteBackupService;
use Illuminate\Console\Command;

class RestoreFreshMart extends Command
{
    protected $signature = 'freshmart:restore
        {backup : Backup filename or path inside the backup directory}
        {--confirm : Confirm destructive replacement of the target database}
        {--database= : SQLite database path}
        {--directory= : Backup directory}';

    protected $description = 'Validate and restore a FreshMart SQLite backup';

    public function handle(SQLiteBackupService $backups): int
    {
        if (! $this->option('confirm')) {
            $this->error('Restore refused. Re-run with --confirm after verifying the target and backup.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restore(
                (string) $this->argument('backup'),
                $this->option('database') ?: null,
                $this->option('directory') ?: null,
            );
            $this->info('Database restored from: '.$result['restored_from']);
            $this->line('Safety backup: '.$result['safety_backup']);
            $this->line('Verified SHA-256: '.$result['sha256']);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
