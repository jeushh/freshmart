<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $temporaryDatabase;

    protected function setUp(): void
    {
        $this->temporaryDatabase = tempnam(sys_get_temp_dir(), 'freshmart-test-');
        $sourceDatabase = __DIR__.'/../../../database/freshmart.sqlite';
        copy($sourceDatabase, $this->temporaryDatabase);
        if (is_file($sourceDatabase.'-wal')) {
            copy($sourceDatabase.'-wal', $this->temporaryDatabase.'-wal');
        }
        putenv("DB_DATABASE={$this->temporaryDatabase}");
        $_ENV['DB_DATABASE'] = $this->temporaryDatabase;
        $_SERVER['DB_DATABASE'] = $this->temporaryDatabase;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->temporaryDatabase);
        @unlink($this->temporaryDatabase.'-shm');
        @unlink($this->temporaryDatabase.'-wal');
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
    }
}
