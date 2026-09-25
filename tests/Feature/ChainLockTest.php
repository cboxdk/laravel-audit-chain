<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Exceptions\UnsupportedChainLock;
use Cbox\AuditChain\Locking\AnchorRowChainLock;
use Cbox\AuditChain\Locking\AutoChainLock;
use Cbox\AuditChain\Locking\PostgresAdvisoryChainLock;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

function useLock(string $driver): void
{
    config(['audit-chain.lock.driver' => $driver]);
    app()->forgetInstance(ChainLock::class);
    app()->forgetInstance(AuditChain::class);
}

/**
 * @return list<string> the statements an append issued, identifier quoting removed
 */
function statementsOfTwoAppends(ChainKey $key): array
{
    /** @var list<string> $sql */
    $sql = [];

    app(AuditChain::class)->record($key, ChainEvent::system('first'));

    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = str_replace(['"', '`'], '', $query->sql);
    });

    app(AuditChain::class)->record($key, ChainEvent::system('second'));

    return $sql;
}

it('uses the anchor lock unless configured otherwise', function (): void {
    expect(config('audit-chain.lock.driver'))->toBe('anchor')
        ->and(app(ChainLock::class))->toBeInstanceOf(AnchorRowChainLock::class);

    useLock('advisory');
    expect(app(ChainLock::class))->toBeInstanceOf(PostgresAdvisoryChainLock::class);

    useLock('auto');
    expect(app(ChainLock::class))->toBeInstanceOf(AutoChainLock::class);
});

it('refuses an unknown lock driver by name', function (): void {
    useLock('optimistic');

    expect(fn () => app(ChainLock::class))->toThrow(UnsupportedChainLock::class, 'optimistic');
});

it('defaults a hand-built chain to the anchor lock too', function (): void {
    // laravel-id and every other host that constructs the chain itself pass no lock.
    $chain = new DatabaseAuditChain(
        app(ChainModels::class),
        app(EntryCodec::class),
        app(CheckpointSigner::class),
        app(CheckpointAnchor::class),
    );

    $lock = (new ReflectionProperty($chain, 'lock'))->getValue($chain);

    expect($lock)->toBeInstanceOf(AnchorRowChainLock::class);
});

it('refuses the advisory lock on an engine that has none, rather than appending unlocked', function (): void {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('this engine has advisory locks');
    }

    useLock('advisory');

    expect(fn () => app(AuditChain::class)->record(ChainKey::of('p', 's'), ChainEvent::system('x')))
        ->toThrow(UnsupportedChainLock::class, 'PostgreSQL');

    expect(DB::table('audit_chain_entries')->count())->toBe(0);
});

it('falls back to the anchor lock under auto on anything but PostgreSQL', function (): void {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('auto means advisory here');
    }

    useLock('auto');

    $sql = statementsOfTwoAppends(ChainKey::of('p', 's'));

    expect(array_filter($sql, static fn (string $s): bool => str_contains($s, 'where audit_chain_entries.id = ?')))->toHaveCount(1)
        ->and(array_filter($sql, static fn (string $s): bool => str_contains($s, 'pg_advisory')))->toBe([]);
});

/**
 * The advisory lock's statement shape, on the engine that has it: isolation pinned,
 * then the chain's advisory lock, then the head read and the insert — no row lock
 * anywhere, and no anchor lookup either.
 */
it('takes a per-chain advisory lock first, and no row lock at all', function (string $driver): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only');
    }

    // The suite's own transaction is committed so this append owns the outermost one.
    DB::commit();
    $this->beforeApplicationDestroyed(fn () => DB::table('audit_chain_entries')->delete());

    useLock($driver);

    $sql = statementsOfTwoAppends(ChainKey::of('p', 's'));

    expect($sql[0])->toBe('set transaction isolation level read committed')
        ->and($sql[1])->toBe('select pg_advisory_xact_lock(?)')
        ->and($sql[2])->toStartWith('select * from audit_chain_entries where partition_key = ? and scope = ? order by sequence desc')
        ->and($sql[3])->toStartWith('insert into audit_chain_entries')
        ->and(array_filter($sql, static fn (string $s): bool => str_contains($s, 'for update') || str_contains($s, 'for share')))->toBe([]);
})->with(['advisory', 'auto']);

it('keeps the caller\'s isolation when the append is nested in its transaction', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only');
    }

    useLock('advisory');

    // Still inside RefreshDatabase's transaction: the append is a savepoint and must not
    // (cannot) change the isolation of a transaction that has already run queries.
    $sql = statementsOfTwoAppends(ChainKey::of('p', 's'));

    expect($sql)->not->toContain('set transaction isolation level read committed')
        ->and($sql)->toContain('select pg_advisory_xact_lock(?)');
});

it('holds the advisory lock until the outermost transaction ends', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only');
    }

    useLock('advisory');

    $key = ChainKey::of('p', 'held');
    $lockKey = PostgresAdvisoryChainLock::keyFor('audit_chain_entries', $key);
    $held = static fn (): int => count(DB::select(
        "select 1 from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and ((classid::bigint << 32) | objid::bigint) = ?",
        [$lockKey],
    ));

    app(AuditChain::class)->record($key, ChainEvent::system('x'));

    // The append committed its savepoint, but the lock lives as long as the caller's
    // transaction: a second appender waits for the caller to commit, which is exactly
    // when the entry becomes visible to it.
    expect($held())->toBe(1);
});

/**
 * Independent known answers: computed by PostgreSQL itself, as
 * ('x' || substr(encode(sha256(<material>), 'hex'), 1, 16))::bit(64)::bigint,
 * over the same NUL-separated material — including a key whose top bit is set.
 */
it('derives the documented, engine-verified advisory key', function (string $scope, int $expected): void {
    expect(PostgresAdvisoryChainLock::keyFor('t', ChainKey::of('p', $scope)))->toBe($expected);
})->with([
    'positive' => ['s', 4427935336394589503],
    'negative (top bit set)' => ['s2', -5338857523627350054],
]);

it('gives every chain and every table its own advisory key', function (): void {
    $keys = [
        PostgresAdvisoryChainLock::keyFor('audit_chain_entries', ChainKey::of('a', 'b')),
        PostgresAdvisoryChainLock::keyFor('audit_chain_entries', ChainKey::of('a', 'c')),
        PostgresAdvisoryChainLock::keyFor('audit_chain_entries', ChainKey::of('b', 'b')),
        PostgresAdvisoryChainLock::keyFor('other_entries', ChainKey::of('a', 'b')),
        // The separator cannot be forged by moving characters between the halves.
        PostgresAdvisoryChainLock::keyFor('audit_chain_entries', ChainKey::of('a/b', 'c')),
        PostgresAdvisoryChainLock::keyFor('audit_chain_entries', ChainKey::of('a', 'b/c')),
    ];

    expect(array_unique($keys))->toHaveCount(count($keys));
});
