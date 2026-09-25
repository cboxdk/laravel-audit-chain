<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Exceptions\CannotAppendToChain;
use Cbox\AuditChain\Models\AuditChainEntry;
use Cbox\AuditChain\Tests\Support\ForkedAppenders;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The append path is the one place where two requests race for the same value — the
 * next position in a chain. Single-writer tests cannot see a lost append; it only shows
 * when appenders overlap.
 *
 * Real contention needs real processes against a server engine. SQLite has no row-level
 * locking and a `:memory:` database is not even shared between connections, so the
 * first test skips there and runs when DB_CONNECTION=pgsql (or mysql / mariadb) points
 * the suite at a server — see docs/getting-started/testing.md. The rest are
 * engine-independent: they inject the exact interleavings the race produces, so every
 * branch of the retry ladder stays covered on the default SQLite run.
 */

function concurrencyKey(): ChainKey
{
    return ChainKey::of('tenant_a', 'contended');
}

it('loses no append when many processes contend on one chain', function (string $strategy): void {
    if ($strategy === 'advisory' && DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('the advisory lock is PostgreSQL-only');
    }

    config(['audit-chain.lock.driver' => $strategy]);
    app()->forgetInstance(ChainLock::class);
    app()->forgetInstance(AuditChain::class);

    // Committed rows outlive the test, so they are removed however it ends — a
    // failing case must not leave rows behind for the next dataset to trip over.
    DB::commit();
    $this->beforeApplicationDestroyed(fn () => DB::table('audit_chain_entries')->delete());

    $writers = 8;
    $perWriter = 100;

    ['written' => $written, 'errors' => $errors] = ForkedAppenders::run(concurrencyKey(), $writers, $perWriter);

    $expected = $writers * $perWriter;

    // Every append reported success...
    expect($errors)->toBe([])
        ->and($written)->toBe($expected);

    // ...every append is on disk, at its own position...
    $chain = DB::table('audit_chain_entries')
        ->where('scope', 'contended')
        ->orderBy('sequence')
        ->pluck('sequence')
        ->map(static fn (mixed $sequence): int => (int) $sequence)
        ->all();

    expect($chain)->toHaveCount($expected)
        // Gapless: a hole is what tamper detection reads as a deletion.
        ->and($chain)->toBe(range(1, $expected));

    // ...and the linkage survived being written by eight processes at once.
    expect(app(AuditChain::class)->verify(concurrencyKey())->valid)->toBeTrue();
})->with(['anchor', 'advisory'])->skip(
    fn (): bool => ! onServerEngine() || ! function_exists('pcntl_fork'),
    'needs a server engine and pcntl: set DB_CONNECTION=pgsql (or mysql / mariadb) to run it',
);

it('re-reads the head and keeps the entry when its position is taken mid-append', function (): void {
    $chain = app(AuditChain::class);

    $chain->record(concurrencyKey(), ChainEvent::system('first'));

    // By the time this append inserts, the position it computed has been claimed by
    // someone else. On PostgreSQL that is a blocked `FOR UPDATE` waking on the old
    // head; here it is injected directly, because SQLite cannot run a second writer.
    $raced = false;

    AuditChainEntry::creating(function () use (&$raced, $chain): void {
        if ($raced) {
            return;
        }

        $raced = true;

        $chain->record(concurrencyKey(), ChainEvent::system('competitor'));
    });

    // A transaction with `attempts: 3` only retries SQLSTATE 40001, never a duplicate
    // key — so without the ladder this threw, and every caller that reports and
    // continues turned it into a silent hole in the trail.
    $entry = $chain->record(concurrencyKey(), ChainEvent::system('second'));

    expect($raced)->toBeTrue()->and($entry->exists)->toBeTrue();

    $sequences = DB::table('audit_chain_entries')->orderBy('sequence')->pluck('sequence')
        ->map(static fn (mixed $sequence): int => (int) $sequence)->all();

    // Gapless, and the entry sits at the head. (The injected competitor ran inside the
    // colliding attempt's transaction, so it is rolled back with it — how many rows
    // survive is the engine's business; that none is lost or misplaced is ours.)
    expect($sequences)->toBe(range(1, count($sequences)))
        ->and($entry->sequence)->toBe(count($sequences))
        ->and($chain->verify(concurrencyKey())->valid)->toBeTrue();
});

it('gives up loudly rather than dropping an entry it cannot place', function (): void {
    $chain = app(AuditChain::class);

    $chain->record(concurrencyKey(), ChainEvent::system('first'));

    // A competitor that takes the position on EVERY attempt, so the budget is spent. A
    // hole reads as a deletion to verification, so the caller has to be told.
    $injecting = false;

    AuditChainEntry::creating(function () use (&$injecting, $chain): void {
        if ($injecting) {
            return;
        }

        $injecting = true;

        try {
            $chain->record(concurrencyKey(), ChainEvent::system('competitor'));
        } finally {
            $injecting = false;
        }
    });

    expect(fn () => $chain->record(concurrencyKey(), ChainEvent::system('second')))
        ->toThrow(CannotAppendToChain::class, 'tenant_a/contended');
});

/**
 * The MariaDB genesis race, in the two properties that fix it. The engine that actually
 * exhibited it is exercised by the forking test above, which is green on MariaDB only
 * with these in place.
 */
it('retries a serialisation failure instead of handing it to the caller', function (): void {
    $chain = app(AuditChain::class);

    // Commit RefreshDatabase's wrapping transaction so the append owns its own
    // outermost transaction, as in a request. A concurrency error INSIDE a caller's
    // transaction is deliberately not retried: the engine has already rolled that
    // transaction back. (The test clears up after itself below.)
    DB::commit();

    $chain->record(concurrencyKey(), ChainEvent::system('first'));

    // Eight writers on one empty chain produce a pile-up, not one deadlock: three
    // consecutive victims is that shape, and what exhausted Laravel's own budget.
    $deadlocks = 0;

    AuditChainEntry::creating(function () use (&$deadlocks): void {
        if ($deadlocks >= 3) {
            return;
        }

        $deadlocks++;

        throw new QueryException(
            'testing',
            'insert into "audit_chain_entries" ("sequence") values (?)',
            [2],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );
    });

    $entry = $chain->record(concurrencyKey(), ChainEvent::system('second'));
    $verified = $chain->verify(concurrencyKey())->valid;

    DB::table('audit_chain_entries')->delete();

    expect($deadlocks)->toBe(3)
        ->and($entry->sequence)->toBe(2)
        ->and($verified)->toBeTrue();
});

it('locks the chain anchor by primary key, and locks nothing at all on an empty chain', function (): void {
    $chain = app(AuditChain::class);

    /** @var list<string> $sql */
    $sql = [];

    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    // Identifier quoting is per grammar; this assertion is about the predicate.
    $bare = static fn (string $statement): string => str_replace(['"', '`'], '', $statement);

    // A read addressed by primary key is the ONLY lock shape that cannot take a gap
    // lock, which is what made eight MariaDB writers deadlock on an empty chain. SQLite
    // compiles no `for update`, so the assertion is on the statement the lock rides on.
    $anchorLock = static fn (string $statement): bool => str_contains($bare($statement), 'select id from audit_chain_entries where audit_chain_entries.id = ?');

    $chain->record(concurrencyKey(), ChainEvent::system('genesis'));

    // Nothing exists to serialise on yet, so nothing is locked: the unique key decides
    // the race and record() absorbs the loser's duplicate key.
    expect(array_filter($sql, $anchorLock))->toBe([]);

    $sql = [];

    $chain->record(concurrencyKey(), ChainEvent::system('second'));

    expect(array_filter($sql, $anchorLock))->toHaveCount(1);

    // ...and taken BEFORE the insert, not alongside it.
    $order = array_values(array_filter(
        $sql,
        static fn (string $statement): bool => $anchorLock($statement) || str_starts_with($bare($statement), 'insert into audit_chain_entries'),
    ));

    expect($order)->toHaveCount(2)
        ->and($anchorLock($order[0]))->toBeTrue();
});

/**
 * The other half of the retry predicate: an error that is NOT contention reaches the
 * caller, once, unchanged. Attempts are counted — asserting only the exception type
 * would pass just as well if it had been retried eight times first.
 */
it('hands a non-contention failure straight to the caller, without retrying it', function (): void {
    $attempts = 0;

    AuditChainEntry::creating(function () use (&$attempts): void {
        $attempts++;

        // SQLSTATE 42S22 — unknown column. A real fault no amount of waiting fixes.
        throw new QueryException(
            'testing',
            'insert into "audit_chain_entries" ("nope") values (?)',
            [1],
            new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nope' in 'field list'"),
        );
    });

    expect(fn () => app(AuditChain::class)->record(concurrencyKey(), ChainEvent::system('boom')))
        ->toThrow(QueryException::class);

    expect($attempts)->toBe(1, "a real fault was retried {$attempts} times as if it were contention");
});
