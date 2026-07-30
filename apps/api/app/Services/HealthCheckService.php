<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthCheckService
{
    public function __construct(
        private readonly SQLiteBackupService $backups,
        private readonly SystemSettingsService $settings,
    ) {}

    public function run(?string $database = null): array
    {
        $checks = [];
        $critical = false;
        $add = function (
            string $name,
            bool $ok,
            string $message,
            bool $isCritical = true,
        ) use (&$checks, &$critical): void {
            $checks[] = compact('name', 'ok', 'message', 'isCritical');
            if (! $ok && $isCritical) {
                $critical = true;
            }
        };

        $environment = (string) app()->environment();
        $debug = (bool) config('app.debug');
        $add('environment', true, $environment, false);
        $add(
            'debug_mode',
            ! ($debug && $environment === 'production'),
            $debug ? 'enabled' : 'disabled',
        );
        $demoEnabled = filter_var(
            env('FRESHMART_SEED_DEMO', false),
            FILTER_VALIDATE_BOOL,
        );
        $demoRisk = $demoEnabled || $this->demoAccountsExist();
        $add(
            'demo_seeders',
            $environment !== 'production' || ! $demoRisk,
            $environment === 'production'
                ? ($demoRisk ? 'demo configuration or accounts detected' : 'disabled')
                : ($demoEnabled ? 'enabled for non-production environment' : 'disabled'),
        );

        try {
            $path = $this->backups->databasePath($database);
            $this->backups->assertIntegrity($path);
            $add('database_integrity', true, 'integrity and foreign keys are valid');
            $add('database_writable', is_writable($path), is_writable($path) ? 'writable' : 'not writable');
        } catch (\Throwable $exception) {
            $add('database_integrity', false, $exception->getMessage());
        }

        try {
            Artisan::call('migrate:status', ['--no-interaction' => true]);
            $pending = str_contains(Artisan::output(), 'Pending');
            $add('migrations', ! $pending, $pending ? 'pending migrations detected' : 'up to date');
        } catch (\Throwable) {
            $add('migrations', false, 'migration status unavailable');
        }

        foreach ([
            'storage' => storage_path(),
            'logs' => storage_path('logs'),
            'cache' => storage_path('framework/cache'),
        ] as $name => $path) {
            $writable = is_dir($path) && is_writable($path);
            $add($name, $writable, $writable ? 'writable' : 'not writable');
        }

        try {
            $settings = $this->settings->all();
            $requiredKeys = [
                'business_name',
                'currency_code',
                'currency_symbol',
                'currency_locale',
                'timezone',
                'tax_rate',
                'tax_inclusive',
                'report_max_date_range_days',
            ];
            $storedKeys = Schema::hasTable('system_settings')
                ? DB::table('system_settings')
                    ->whereIn('setting_key', $requiredKeys)
                    ->distinct()
                    ->count('setting_key')
                : 0;
            $valid = in_array($settings['currency_code'], ['PHP', 'USD'], true)
                && is_numeric($settings['tax_rate'])
                && (float) $settings['tax_rate'] >= 0
                && (float) $settings['tax_rate'] <= 100
                && $storedKeys === count($requiredKeys);
            $add('settings', $valid, $valid ? 'valid' : 'invalid currency or tax configuration');
        } catch (\Throwable) {
            $add('settings', false, 'settings could not be loaded');
        }

        $backupDirectory = $this->backups->backupDirectory();
        $backupParent = dirname($backupDirectory);
        $backupReady = (is_dir($backupDirectory) && is_writable($backupDirectory))
            || (! is_dir($backupDirectory) && is_writable($backupParent));
        $add(
            'backup_directory',
            $backupReady,
            is_dir($backupDirectory) ? 'available' : 'will be created on first backup',
            false,
        );
        $add(
            'cache_configuration',
            config('cache.default') !== 'array' || $environment !== 'production',
            'driver: '.config('cache.default'),
            false,
        );
        $add(
            'queue',
            config('queue.default') !== 'sync' || $environment !== 'production',
            'driver: '.config('queue.default'),
            false,
        );

        return [
            'healthy' => ! $critical,
            'checked_at' => now('UTC')->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function demoAccountsExist(): bool
    {
        if (! Schema::hasTable('admin_users')) {
            return false;
        }

        return DB::table('admin_users')
            ->whereIn('username', ['cashier', 'hr', 'finance', 'operations', 'inventory', 'employee'])
            ->exists();
    }
}
