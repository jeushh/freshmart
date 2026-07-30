<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupAndHealthTest extends TestCase
{
    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = sys_get_temp_dir().'/freshmart-backups-'.bin2hex(random_bytes(5));
        mkdir($this->backupDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupDirectory);
        parent::tearDown();
    }

    public function test_backup_creates_valid_database_and_checksum_manifest(): void
    {
        $this->artisan('freshmart:backup', [
            '--directory' => $this->backupDirectory,
            '--retention' => 2,
        ])->assertSuccessful();

        $backup = $this->normalBackups()[0];
        $manifest = json_decode(
            (string) file_get_contents($backup.'.manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $manifest['format_version']);
        $this->assertSame(basename($backup), $manifest['database_filename']);
        $this->assertSame(hash_file('sha256', $backup), $manifest['sha256']);
        $this->assertSame('ok', (new \PDO('sqlite:'.$backup))
            ->query('PRAGMA integrity_check')
            ->fetchColumn());
    }

    public function test_restore_requires_confirmation_and_restores_validated_state(): void
    {
        DB::table('system_settings')->insert([
            'setting_key' => 'restore_marker',
            'setting_value' => 'before-backup',
        ]);
        $this->artisan('freshmart:backup', [
            '--directory' => $this->backupDirectory,
        ])->assertSuccessful();
        $backup = $this->normalBackups()[0];
        DB::table('system_settings')->where('setting_key', 'restore_marker')->delete();
        Cache::put('restore-cache-marker', 'stale', 60);

        $this->artisan('freshmart:restore', [
            'backup' => basename($backup),
            '--directory' => $this->backupDirectory,
        ])->assertFailed();
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'restore_marker']);

        $this->artisan('freshmart:restore', [
            'backup' => basename($backup),
            '--directory' => $this->backupDirectory,
            '--confirm' => true,
        ])->assertSuccessful();
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'restore_marker',
            'setting_value' => 'before-backup',
        ]);
        $this->assertNull(Cache::get('restore-cache-marker'));
        $this->assertNotEmpty(glob($this->backupDirectory.'/pre-restore-*.sqlite'));
    }

    public function test_restore_rejects_a_tampered_backup(): void
    {
        $this->artisan('freshmart:backup', [
            '--directory' => $this->backupDirectory,
        ])->assertSuccessful();
        $backup = $this->normalBackups()[0];
        file_put_contents($backup, 'tampered', FILE_APPEND);

        $this->artisan('freshmart:restore', [
            'backup' => basename($backup),
            '--directory' => $this->backupDirectory,
            '--confirm' => true,
        ])->assertFailed();
    }

    public function test_restore_rejects_invalid_sqlite_even_with_a_matching_checksum(): void
    {
        $this->artisan('freshmart:backup', [
            '--directory' => $this->backupDirectory,
        ])->assertSuccessful();
        $backup = $this->normalBackups()[0];
        file_put_contents($backup, 'not a sqlite database');
        $manifestPath = $backup.'.manifest.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $manifest['database_size'] = filesize($backup);
        $manifest['sha256'] = hash_file('sha256', $backup);
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->artisan('freshmart:restore', [
            'backup' => basename($backup),
            '--directory' => $this->backupDirectory,
            '--confirm' => true,
        ])->assertFailed();
    }

    public function test_backup_refuses_the_preserved_legacy_database(): void
    {
        $legacy = dirname(base_path(), 2).'/database/freshmart.sqlite';
        $this->artisan('freshmart:backup', [
            '--database' => $legacy,
            '--directory' => $this->backupDirectory,
        ])->assertFailed();
        $this->assertSame([], $this->normalBackups());
    }

    public function test_health_command_reports_a_ready_test_environment(): void
    {
        $this->artisan('freshmart:health')->assertSuccessful();
    }

    public function test_health_command_fails_for_a_missing_database_without_printing_secrets(): void
    {
        $missing = $this->backupDirectory.'/missing.sqlite';
        $this->artisan('freshmart:health', ['--database' => $missing])
            ->assertFailed();

        Artisan::call('freshmart:health');
        $output = Artisan::output();
        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertStringNotContainsString('FRESHMART_ADMIN_PASSWORD', $output);
    }

    private function normalBackups(): array
    {
        return glob($this->backupDirectory.'/freshmart-*.sqlite') ?: [];
    }
}
