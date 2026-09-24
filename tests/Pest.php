<?php

declare(strict_types=1);

use Cbox\AuditChain\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class)->in('Feature', 'Unit');

// Feature tests touch the database. On SQLite `:memory:` the database lives in the
// connection, so it is rebuilt for every test anyway; on a server engine
// RefreshDatabase migrates once per process and wraps each test in a transaction.
uses(RefreshDatabase::class)->in('Feature');

/**
 * Whether the suite is pointed at a server engine (see TestCase::databaseConnection()).
 */
function onServerEngine(): bool
{
    return in_array(getenv('DB_CONNECTION'), ['pgsql', 'mysql', 'mariadb'], true);
}
