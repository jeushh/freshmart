<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

class FreshMartHealth extends Command
{
    protected $signature = 'freshmart:health {--database= : SQLite database path}';

    protected $description = 'Run deployment and runtime readiness checks';

    public function handle(HealthCheckService $health): int
    {
        $result = $health->run($this->option('database') ?: null);
        foreach ($result['checks'] as $check) {
            $status = $check['ok'] ? 'PASS' : ($check['isCritical'] ? 'FAIL' : 'WARN');
            $this->line(sprintf('[%s] %s: %s', $status, $check['name'], $check['message']));
        }
        $this->newLine();
        $this->line('Checked at: '.$result['checked_at']);
        $result['healthy']
            ? $this->info('FreshMart health checks passed.')
            : $this->error('FreshMart has critical health-check failures.');

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
