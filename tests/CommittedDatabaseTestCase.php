<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/** Real commits are essential to verify the durable marker before a financial HTTP request. */
abstract class CommittedDatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); // TestCase rejects any database other than :memory: or *_testing.
        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app !== null) {
                Schema::dropAllTables();
                RefreshDatabaseState::$migrated = false;
            }
        } finally {
            parent::tearDown();
        }
    }
}
