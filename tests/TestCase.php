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

        if (! $application->environment('testing')
            || $defaultConnection !== 'sqlite'
            || $sqliteDatabase !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests must run only with APP_ENV=testing and an in-memory SQLite database.',
            );
        }

        return $application;
    }
}
