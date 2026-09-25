<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Security\DatabaseEngine;
use Cbox\AuditChain\Security\LeastPrivilegeGrants;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\Tests\Support\ForkedAppenders;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * THE LEAST-PRIVILEGE STORY, ON REAL ENGINES.
 *
 * The documented deployment runs the app as a role that may only SELECT and INSERT on
 * the chain tables, with append-only triggers on both. These tests create exactly that
 * role, apply exactly the SQL `audit-chain:grants` prints (LeastPrivilegeGrants), and
 * then append, verify, checkpoint and contend AS that role.
 *
 * Server engines only. PostgreSQL uses the suite's own user (a superuser in the official
 * image, and in CI). MySQL and MariaDB need an account that may create users:
 * DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD (CI passes root); without it they skip.
 */

const RUNTIME_ROLE = 'audit_chain_rt';
const RUNTIME_PASSWORD = 'runtime-secret';

/**
 * The connection that creates the role and applies the grants, or null when this
 * engine cannot be administered from here.
 */
function adminConnection(): ?Connection
{
    if (! onServerEngine()) {
        return null;
    }

    if (getenv('DB_CONNECTION') === 'pgsql') {
        return DB::connection();
    }

    $username = getenv('DB_ADMIN_USERNAME');

    if (! is_string($username) || $username === '') {
        return null;
    }

    config(['database.connections.audit_admin' => array_merge(config('database.connections.testing'), [
        'username' => $username,
        'password' => (string) getenv('DB_ADMIN_PASSWORD'),
    ])]);

    return DB::connection('audit_admin');
}

function engineUnderTest(): DatabaseEngine
{
    $engine = DatabaseEngine::of(DB::connection());

    expect($engine)->not->toBeNull();

    return $engine ?? DatabaseEngine::Postgres;
}

/**
 * Create the runtime role with ONLY the grants for `$lock`, make the tables append-only,
 * and point the chain at a connection that logs in as that role.
 *
 * @param  bool  $withRowLockGrant  false to withhold the anchor lock's UPDATE(hash) grant,
 *                                  which is how the refusal is reproduced
 */
function actAsRuntimeRole(Connection $admin, string $lock, bool $withRowLockGrant = true): LeastPrivilegeGrants
{
    // Commit RefreshDatabase's wrapping transaction: the runtime role is a separate
    // connection, and on PostgreSQL the role and grants are transactional DDL.
    DB::commit();

    $engine = engineUnderTest();

    $grants = new LeastPrivilegeGrants(
        engine: $engine,
        role: RUNTIME_ROLE,
        lock: $lock,
        database: $engine === DatabaseEngine::Postgres ? null : $admin->getDatabaseName(),
    );

    dropRuntimeRole($admin, $grants);

    if ($engine === DatabaseEngine::Postgres) {
        $admin->statement('CREATE ROLE "'.RUNTIME_ROLE.'" LOGIN PASSWORD \''.RUNTIME_PASSWORD.'\'');
    } else {
        $admin->statement("CREATE USER '".RUNTIME_ROLE."'@'%' IDENTIFIED BY '".RUNTIME_PASSWORD."'");
    }

    foreach ($grants->appendOnly() as $statement) {
        $admin->unprepared($statement);
    }

    foreach ($grants->grants() as $statement) {
        if (! $withRowLockGrant && str_starts_with($statement, 'GRANT UPDATE')) {
            continue;
        }

        $admin->statement($statement);
    }

    // Undone however the test ends, so no other test ever meets the triggers.
    test()->beforeApplicationDestroyed(function () use ($admin, $grants): void {
        foreach (array_keys(DB::getConnections()) as $name) {
            if ($name !== $admin->getName()) {
                DB::disconnect($name);
            }
        }

        foreach ($grants->dropAppendOnly() as $statement) {
            $admin->statement($statement);
        }

        $admin->table('audit_chain_entries')->delete();
        $admin->table('audit_chain_checkpoints')->delete();

        dropRuntimeRole($admin, $grants);
    });

    config([
        'database.connections.audit_runtime' => array_merge(config('database.connections.testing'), [
            'username' => RUNTIME_ROLE,
            'password' => RUNTIME_PASSWORD,
        ]),
        'audit-chain.storage.connection' => 'audit_runtime',
        'audit-chain.lock.driver' => $lock,
    ]);

    foreach ([ChainModels::class, ChainLock::class, ChainInventory::class, AuditChain::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    // It really is the runtime role doing the work.
    expect(app(ChainModels::class)->connection()->getName())->toBe('audit_runtime');

    return $grants;
}

function dropRuntimeRole(Connection $admin, LeastPrivilegeGrants $grants): void
{
    if ($grants->engine === DatabaseEngine::Postgres) {
        $exists = $admin->selectOne('select 1 as present from pg_roles where rolname = ?', [RUNTIME_ROLE]);

        if ($exists !== null) {
            $admin->statement('DROP OWNED BY "'.RUNTIME_ROLE.'"');
            $admin->statement('DROP ROLE "'.RUNTIME_ROLE.'"');
        }

        return;
    }

    $admin->statement("DROP USER IF EXISTS '".RUNTIME_ROLE."'@'%'");
}

/**
 * The SQLSTATE of a refused statement, or null if it was not refused.
 */
function refusal(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $refused) {
        $state = $refused->errorInfo[0] ?? null;

        return is_string($state) ? $state : 'refused';
    }

    return null;
}

beforeEach(function (): void {
    if (adminConnection() === null) {
        $this->markTestSkipped(onServerEngine()
            ? 'MySQL/MariaDB need DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD to create the runtime user'
            : 'needs a server engine: set DB_CONNECTION=pgsql (or mysql / mariadb)');
    }
});

it('appends, verifies and checkpoints as a role with only the documented grants', function (string $lock): void {
    if ($lock === LeastPrivilegeGrants::LOCK_ADVISORY && engineUnderTest() !== DatabaseEngine::Postgres) {
        $this->markTestSkipped('the advisory lock is PostgreSQL-only');
    }

    $admin = adminConnection();
    expect($admin)->not->toBeNull();

    $grants = actAsRuntimeRole($admin ?? DB::connection(), $lock);

    // What the role holds is what the grant matrix says, and no more.
    expect($grants->needsRowLockGrant())->toBe($lock === 'anchor' && engineUnderTest() !== DatabaseEngine::MariaDb);

    $chain = app(AuditChain::class);
    $a = ChainKey::of('tenant_a', 'ledger');
    $b = ChainKey::of('tenant_b', 'ledger');

    // Several appends per chain: the first has no anchor to lock, every later one does.
    foreach (range(1, 3) as $i) {
        $chain->record($a, ChainEvent::system('a'.$i));
    }

    $chain->record($b, ChainEvent::system('b1'));
    $chain->record($b, ChainEvent::system('b2'));

    // ...including inside the caller's own transaction, as consumers do.
    app(ChainModels::class)->connection()->transaction(fn () => $chain->record($a, ChainEvent::system('a4')));

    $checkpoint = $chain->checkpoint($a);

    expect($chain->head($a))->toBe(4)
        ->and($chain->head($b))->toBe(2)
        ->and($checkpoint->up_to_sequence)->toBe(4)
        ->and($chain->verify($a)->valid)->toBeTrue()
        ->and($chain->verify($b)->valid)->toBeTrue();

    // And the role cannot rewrite what it wrote — through the grant it lacks, or through
    // the trigger that stands behind the one column it may "update".
    $runtime = DB::connection('audit_runtime');

    expect(refusal(fn () => $runtime->table('audit_chain_entries')->update(['hash' => str_repeat('0', 64)])))->not->toBeNull()
        ->and(refusal(fn () => $runtime->table('audit_chain_entries')->update(['action' => 'forged'])))->not->toBeNull()
        ->and(refusal(fn () => $runtime->table('audit_chain_entries')->delete()))->not->toBeNull()
        ->and(refusal(fn () => $runtime->table('audit_chain_checkpoints')->delete()))->not->toBeNull()
        ->and($chain->verify($a)->valid)->toBeTrue();
})->with([LeastPrivilegeGrants::LOCK_ADVISORY, LeastPrivilegeGrants::LOCK_ANCHOR]);

it('makes the tables append-only for their owner too', function (): void {
    $admin = adminConnection() ?? DB::connection();

    actAsRuntimeRole($admin, engineUnderTest() === DatabaseEngine::Postgres ? 'advisory' : 'anchor');

    app(AuditChain::class)->record(ChainKey::of('t', 's'), ChainEvent::system('x'));

    expect(refusal(fn () => $admin->table('audit_chain_entries')->update(['action' => 'forged'])))->not->toBeNull()
        ->and(refusal(fn () => $admin->table('audit_chain_entries')->delete()))->not->toBeNull()
        ->and($admin->table('audit_chain_entries')->value('action'))->toBe('x');
});

/**
 * The bug this guards: the anchor lock is SELECT … FOR UPDATE, and on PostgreSQL and
 * MySQL 8 a row lock is a write privilege. A role with SELECT and INSERT only writes a
 * chain's first entry (there is no anchor to lock yet) and is refused on the second.
 * MariaDB does not ask for it.
 */
it('refuses the anchor lock to a role without the row-lock grant, where the engine asks for one', function (): void {
    $admin = adminConnection() ?? DB::connection();
    $engine = engineUnderTest();

    actAsRuntimeRole($admin, LeastPrivilegeGrants::LOCK_ANCHOR, withRowLockGrant: false);

    $chain = app(AuditChain::class);
    $key = ChainKey::of('tenant_a', 'ledger');

    $chain->record($key, ChainEvent::system('first'));

    $state = refusal(fn () => $chain->record($key, ChainEvent::system('second')));

    if ($engine === DatabaseEngine::MariaDb) {
        expect($state)->toBeNull('MariaDB now asks for a row-lock grant: update DatabaseEngine::rowLockNeedsUpdate() and the docs');

        return;
    }

    // PostgreSQL: insufficient_privilege. MySQL: 42000 with error 1142 ("SELECT with
    // locking clause command denied").
    expect($state)->toBe($engine === DatabaseEngine::Postgres ? '42501' : '42000')
        ->and($chain->head($key))->toBe(1);
});

it('loses no append when eight writers contend as the least-privileged role', function (string $lock): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('needs pcntl');
    }

    if ($lock === LeastPrivilegeGrants::LOCK_ADVISORY && engineUnderTest() !== DatabaseEngine::Postgres) {
        $this->markTestSkipped('the advisory lock is PostgreSQL-only');
    }

    $admin = adminConnection() ?? DB::connection();

    actAsRuntimeRole($admin, $lock);

    $key = ChainKey::of('tenant_a', 'contended');
    $report = ForkedAppenders::run($key, writers: 8, perWriter: 100);

    expect($report['errors'])->toBe([])
        ->and($report['written'])->toBe(800);

    $sequences = $admin->table('audit_chain_entries')->where('scope', 'contended')->orderBy('sequence')->pluck('sequence')
        ->map(static fn (mixed $sequence): int => (int) $sequence)->all();

    expect($sequences)->toBe(range(1, 800))
        ->and(app(AuditChain::class)->verify($key)->valid)->toBeTrue();
})->with([LeastPrivilegeGrants::LOCK_ADVISORY, LeastPrivilegeGrants::LOCK_ANCHOR]);
