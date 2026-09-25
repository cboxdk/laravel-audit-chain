<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Locking;

use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * The default {@see ChainLock}: appenders serialise on the chain's ANCHOR row — its
 * first entry, sequence 1 — with `SELECT … FOR UPDATE` by primary key.
 *
 * Why the anchor and not the head: under READ COMMITTED a blocked `FOR UPDATE`
 * re-checks its predicate against the row it was waiting on, NOT against the
 * `ORDER BY sequence DESC LIMIT 1` that picked it — so a waiter on the head wakes still
 * holding the OLD head and computes a sequence that has just been taken. The anchor's
 * predicate is an equality on the unique key, so it cannot go stale: whoever holds it
 * reads the head afterwards and sees the previous holder's committed entry. Measured on
 * PostgreSQL 16, 8 writers x 100 appends went from 100 written / 700 lost to 800
 * written / 0 lost. Measured 800/800 on MySQL 8.4 and MariaDB 11.8 too.
 *
 * An empty chain has no anchor, so nothing is locked: the unique key plus the retry
 * decide that race (see anchorId() for why it must not be a locking read).
 *
 * ## Privileges
 *
 * A row lock is a write-class privilege on PostgreSQL and MySQL 8, not a read:
 *
 * - PostgreSQL 16 refuses every row-lock mode (FOR UPDATE, NO KEY UPDATE, SHARE,
 *   KEY SHARE) to a role with no UPDATE on at least one column of the table — SQLSTATE
 *   42501 on the second append of every chain (the first has no anchor to lock).
 * - MySQL 8.4 refuses `SELECT … FOR UPDATE` without UPDATE (a single column is enough),
 *   DELETE or LOCK TABLES — error 1142.
 * - MariaDB 11.8 grants it on SELECT alone.
 *
 * So on PostgreSQL and MySQL a least-privilege runtime role needs `UPDATE` on one
 * column (`hash`), with an append-only trigger making that grant unusable for an actual
 * UPDATE. On PostgreSQL, {@see PostgresAdvisoryChainLock} needs no table privilege at
 * all. docs/security/least-privilege.md has the exact grants, and they are tested.
 *
 * ## Isolation
 *
 * Measured at each session default (8 writers x 100 appends): MySQL 8.4 800/800 at READ
 * COMMITTED, REPEATABLE READ (its default) and SERIALIZABLE; MariaDB 11.8 800/800 at READ
 * COMMITTED and REPEATABLE READ (its default) and 2 of 800 lost to deadlocks past the
 * retry budget at SERIALIZABLE; PostgreSQL 16 800/800 at READ COMMITTED (its default)
 * and every writer exhausting its retries at REPEATABLE READ and SERIALIZABLE, because
 * there the locking read fixes the snapshot before it waits and the head read after it is
 * stale. Every failure is loud (CannotAppendToChain). This strategy is kept exactly as it
 * was measured; on PostgreSQL at a stricter level, use {@see PostgresAdvisoryChainLock},
 * which pins READ COMMITTED for its own transaction.
 */
class AnchorRowChainLock implements ChainLock
{
    /**
     * The chain position appenders serialise on.
     */
    public const ANCHOR_SEQUENCE = 1;

    public function withLock(ChainModels $models, ChainKey $key, Closure $append): mixed
    {
        // Located BEFORE the transaction opens, and re-located on every attempt (the
        // caller calls this once per attempt). See anchorId() for both halves of why.
        $anchorId = $this->anchorId($models, $key);

        return $models->connection()->transaction(function () use ($models, $anchorId, $append): mixed {
            if ($anchorId !== null) {
                $this->lockAnchor($models, $anchorId);
            }

            // Holding the anchor already excludes every other appender on this chain,
            // so the head read `$append` does needs no lock of its own. And when there
            // is no anchor to hold (an empty chain, or one whose genesis row is gone)
            // this deliberately takes NO lock either: on an empty chain every locking
            // read is a range predicate that matches nothing, which InnoDB answers with
            // a gap lock, which is the deadlock. The unique key plus the caller's retry
            // is what makes the unlocked read safe.
            return $append();
        }, attempts: 1);
    }

    /**
     * Find the chain's anchor — its genesis row (sequence 1) — or null if the chain
     * is empty. Entries are append-only and never pruned, so that row exists for
     * every non-empty chain, is never updated, and keeps its id forever.
     *
     * ## Why the anchor is FOUND unlocked, and found OUTSIDE the transaction
     *
     * The obvious spelling — one `where sequence = 1 … for update` inside the
     * transaction — is a locking read whose predicate matches NO ROW while the chain
     * is empty, and InnoDB answers that by locking the gap the row would have
     * occupied. Eight processes opening a brand-new chain each take that gap lock,
     * then each needs an insert-intention lock inside the very gap the other seven
     * hold. MariaDB 11.8 resolves the pile-up as SQLSTATE 40001 (error 1213) rather
     * than the duplicate key step 2 absorbs. Measured: 6 of 800 appends lost on
     * MariaDB 11.8.8, 800/800 on MySQL 8.4 and PostgreSQL 16 — the same statement,
     * a different deadlock detector. So the search is a PLAIN read and the lock is
     * taken separately, by PRIMARY KEY, on a row already known to exist: an exact
     * primary-key match takes a record lock and can never take a gap lock.
     *
     * That leaves WHERE the plain read runs, which is not a detail. MySQL and MariaDB
     * default to REPEATABLE READ, where a transaction's FIRST consistent read fixes
     * the snapshot every later consistent read in it is answered from. Run the search
     * inside the transaction and it becomes that first read — so a waiter that then
     * blocks on the anchor wakes up and reads the head from a snapshot taken BEFORE
     * it got the lock, i.e. the exact stale head the anchor exists to prevent. This
     * was measured, not reasoned about: with the search inside the transaction,
     * MariaDB went from 6 lost appends in 800 to the whole retry budget exhausted on
     * duplicate keys, hundreds of times. Outside, the first statement in the
     * transaction is the anchor's locking read (locking reads see the latest
     * committed row, snapshot or not), the head read after it establishes the
     * snapshot, and it sees the previous holder's commit.
     *
     * The cost is one extra indexed lookup per append on a key the append uses anyway.
     *
     * Two reads mean the anchor could vanish between them — only tail deletion does
     * that, which is tampering. Then the locking read finds nothing, the append falls
     * through to the unique key, and that is the same path as a genuinely empty chain.
     */
    private function anchorId(ChainModels $models, ChainKey $key): int|string|null
    {
        $model = $models->newEntry();

        $anchorId = $models->entriesOf($key)
            ->where('sequence', self::ANCHOR_SEQUENCE)
            ->value($model->getKeyName());

        return is_string($anchorId) || is_int($anchorId) ? $anchorId : null;
    }

    /**
     * Take the chain's serialisation lock: an exact primary-key match, so a record
     * lock and never a gap lock.
     *
     * SQLite compiles no lock clause; its writes serialise on the database anyway.
     */
    private function lockAnchor(ChainModels $models, int|string $anchorId): void
    {
        $model = $models->newEntry();

        $models->entries()
            ->whereKey($anchorId)
            ->lockForUpdate()
            ->value($model->getKeyName());
    }
}
