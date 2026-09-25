<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Locking;

use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Exceptions\UnsupportedChainLock;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * A {@see ChainLock} for PostgreSQL that needs NO table privilege: appenders to one chain
 * serialise on a transaction-scoped advisory lock, `pg_advisory_xact_lock(key)`, with the
 * key derived from the table and the chain key ({@see keyFor()}).
 *
 * Exists because the default anchor lock is `SELECT … FOR UPDATE`, and PostgreSQL grants
 * no row-lock mode (FOR UPDATE, NO KEY UPDATE, SHARE, KEY SHARE) to a role without UPDATE
 * on at least one column: a runtime role limited to SELECT and INSERT wrote every chain's
 * first entry and was refused (SQLSTATE 42501) on every later one. Advisory locks are
 * available to any role.
 *
 * ## Why it is correct
 *
 * The lock is the transaction's first statement after the isolation pin, and every
 * appender to the chain takes the same key, so appenders run one at a time; the head read
 * after the lock, a fresh READ COMMITTED snapshot, sees the previous appender's committed
 * entry. Unlike the anchor lock it also covers a chain's FIRST entry (there is no row to
 * wait for, but there is a key). The unique (partition, scope, sequence) key and the
 * caller's retry ladder stay behind it unchanged: the lock is for liveness, the key for
 * safety.
 *
 * ## Collisions only over-serialise
 *
 * Keys are 64-bit. Two chains whose keys collide — or a chain whose key equals a
 * single-bigint advisory lock the host application takes itself — share a lock, so their
 * appends wait for each other. Nothing else happens: correctness never rested on the lock,
 * so a shared lock costs throughput, never an entry. (Advisory locks are per database, so
 * other databases on the same server never collide.)
 *
 * ## Isolation, and why it is pinned
 *
 * Under REPEATABLE READ or SERIALIZABLE, PostgreSQL fixes a transaction's snapshot at its
 * first statement — which would be the lock statement, BEFORE it waits. The head read
 * after it would then be answered from a snapshot older than the lock, i.e. the stale head
 * the lock exists to prevent, and contended appends exhaust their retries. Measured with
 * the anchor lock at REPEATABLE READ: 8 writers x 100 appends, every writer failing.
 * So when this append OWNS its transaction, its first statement is
 * `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` — the level the append is designed
 * for, and a statement that takes no snapshot. Measured: 800/800 at READ COMMITTED,
 * REPEATABLE READ and SERIALIZABLE session defaults.
 *
 * ## Nested in the caller's transaction
 *
 * The attempt is then a savepoint and cannot change the caller's isolation level. The
 * lock is held until the caller's OUTERMOST transaction ends — which is exactly when the
 * entry becomes visible to the next appender, so that is the right length. At READ
 * COMMITTED (PostgreSQL's default) this is correct as is. Under a caller's REPEATABLE READ
 * transaction that has already run a query, the head read uses the caller's snapshot, as
 * with any strategy: a colliding retry re-reads the same stale head and the append ends in
 * CannotAppendToChain — loud, never a lost or misplaced entry. Two transactions appending
 * to chains A and B in opposite orders can deadlock; PostgreSQL detects it (40P01) and the
 * caller sees it, as with any lock.
 *
 * ## Release
 *
 * Transaction-scoped: PostgreSQL releases it at commit or rollback and it cannot be
 * released early, so a crashed worker cannot leave a chain locked, and it is safe behind a
 * transaction-pooling proxy (PgBouncer) where a session-level lock would not be.
 *
 * Every process appending to the same chains must use the same strategy: an anchor-locker
 * and an advisory-locker do not exclude each other.
 */
class PostgresAdvisoryChainLock implements ChainLock
{
    /**
     * Mixed into every key, so this package's locks are recognisable in pg_locks and a
     * future key scheme can never reuse a key for a different chain.
     */
    public const NAMESPACE = 'cbox/audit-chain/advisory/v1';

    public function withLock(ChainModels $models, ChainKey $key, Closure $append): mixed
    {
        $connection = $models->connection();
        $driver = $connection->getDriverName();

        if ($driver !== 'pgsql') {
            throw UnsupportedChainLock::forDriver('advisory', $driver, 'PostgreSQL');
        }

        $lockKey = self::keyFor($models->newEntry()->getTable(), $key);

        // Whether this append opens the outermost transaction, or runs as a savepoint
        // inside the caller's. Only an outermost transaction can still choose its
        // isolation level.
        $ownsTransaction = $connection->transactionLevel() === 0;

        return $connection->transaction(function () use ($connection, $lockKey, $append, $ownsTransaction): mixed {
            if ($ownsTransaction) {
                // Must precede every query in the transaction, and takes no snapshot.
                $connection->statement('set transaction isolation level read committed');
            }

            // Released by PostgreSQL itself when the transaction — the OUTERMOST one, if
            // this is nested — commits or rolls back. Never explicitly.
            $connection->select('select pg_advisory_xact_lock(?)', [$lockKey]);

            return $append();
        }, attempts: 1);
    }

    /**
     * The signed 64-bit advisory-lock key for one chain in one table: the first eight
     * bytes of SHA-256 over the namespace, the table, the partition and the scope,
     * NUL-separated (none of them can contain a NUL), read as a big-endian signed
     * integer. Deterministic across processes and PHP builds.
     */
    public static function keyFor(string $table, ChainKey $key): int
    {
        $digest = hash('sha256', self::NAMESPACE."\0".$table."\0".$key->partition."\0".$key->scope, true);

        $halves = unpack('Nhigh/Nlow', substr($digest, 0, 8));

        if (! is_array($halves) || ! is_int($halves['high'] ?? null) || ! is_int($halves['low'] ?? null)) {
            throw new \LogicException('Could not derive an advisory-lock key.');
        }

        // PHP integers are 64-bit two's complement: the shift wraps into the sign bit
        // exactly as a bigint would.
        return ($halves['high'] << 32) | $halves['low'];
    }
}
