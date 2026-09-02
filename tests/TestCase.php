<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $database = $application->make('config')->get('database');
        $defaultConnection = is_array($database) ? $database['default'] ?? null : null;
        $sqliteDatabase = is_array($database)
            ? $database['connections']['sqlite']['database'] ?? null
            : null;
        $postgresDatabase = is_array($database)
            ? $database['connections']['pgsql']['database'] ?? null
            : null;
        $usesInMemorySqlite = $defaultConnection === 'sqlite'
            && $sqliteDatabase === ':memory:';
        $usesDedicatedTestingPostgres = $defaultConnection === 'pgsql'
            && is_string($postgresDatabase)
            && str_ends_with(strtolower($postgresDatabase), '_testing');

        if (! $application->environment('testing')
            || (! $usesInMemorySqlite && ! $usesDedicatedTestingPostgres)
        ) {
            throw new RuntimeException(
                'Tests require APP_ENV=testing and either SQLite :memory: or a dedicated PostgreSQL database ending in _testing.',
            );
        }

        return $application;
    }
}
