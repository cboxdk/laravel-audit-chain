<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests;

use Cbox\AuditChain\AuditChainServiceProvider;
use Cbox\AuditChain\Testing\InteractsWithAuditChain;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    use InteractsWithAuditChain;

    /**
     * A FIXED Ed25519 seed, so signatures in the suite are reproducible (Ed25519 is
     * deterministic) and known-answer tests can pin exact tokens. Test-only.
     */
    public const TEST_SEED_BASE64 = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwc=';

    public const TEST_KEY_ID = 'test-key';

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AuditChainServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        // The package's own publishable migration, then test-only schema on top of it.
        $paths = [dirname(__DIR__).'/database/migrations', __DIR__.'/Fixtures/migrations'];

        // Only tests that use RefreshDatabase (everything under Feature/, see
        // tests/Pest.php) get a schema; unit tests never touch the database. Registering
        // the paths with the migrator, rather than migrating them here, is what lets
        // RefreshDatabase migrate ONCE per process on a server engine and wrap each test
        // in a transaction — Testbench's own helper would reset its "already migrated"
        // state after every test and rebuild the schema each time.
        if (static::usesRefreshDatabaseTestingConcern()) {
            load_migration_paths($this->app, $paths);
        }
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());

        $app['config']->set('audit-chain.signing.key_id', self::TEST_KEY_ID);
        $app['config']->set('audit-chain.signing.secret_key', self::TEST_SEED_BASE64);
    }

    /**
     * The suite runs on in-memory SQLite by default — fast and hermetic per test.
     *
     * SQLite cannot express row-level locking or two writers at once, so the one thing
     * only a server engine can prove (appends serialising correctly under contention)
     * needs one: set DB_CONNECTION to `pgsql`, `mysql` or `mariadb` plus the usual DB_*
     * variables and the whole suite, including the forking concurrency test, runs
     * against it. See docs/getting-started/testing.md.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        $driver = getenv('DB_CONNECTION');

        if (in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DB_DATABASE') ?: 'audit_chain',
                'username' => getenv('DB_USERNAME') ?: 'audit_chain',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
                // e.g. DB_ISOLATION_LEVEL="repeatable read" — how the concurrency tests
                // were measured under each engine's non-default isolation level. Unset:
                // the engine's own default (READ COMMITTED on PostgreSQL, REPEATABLE
                // READ on MySQL and MariaDB).
                'isolation_level' => getenv('DB_ISOLATION_LEVEL') ?: null,
            ];
        }

        return [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }
}
