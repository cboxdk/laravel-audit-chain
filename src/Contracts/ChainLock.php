<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\Locking\AnchorRowChainLock;
use Cbox\AuditChain\Locking\PostgresAdvisoryChainLock;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * How appenders to ONE chain are serialised.
 *
 * Every append reads the chain's head and inserts the next position. Two writers that
 * read the same head collide on the unique (partition, scope, sequence) key; the append
 * then re-reads and retries, so a collision is never a lost or misplaced entry — the
 * unique key is what makes the chain SAFE. The lock is what makes it LIVE: without one,
 * eight writers on one chain collide on nearly every attempt and exhaust the retry
 * budget. A strategy's job is to make the head read after it see the previous
 * appender's committed entry.
 *
 * The strategies differ in what they ask of the database role that runs the append,
 * which is the whole reason this is a seam:
 *
 * - {@see AnchorRowChainLock} (default): `SELECT … FOR UPDATE`
 *   on the chain's first entry. Measured on PostgreSQL 16, MySQL 8.4 and MariaDB 11.8.
 *   PostgreSQL and MySQL 8 refuse a row lock to a role without UPDATE on the table (on
 *   at least one column).
 * - {@see PostgresAdvisoryChainLock}: a transaction-scoped
 *   advisory lock per chain. PostgreSQL only; needs no table privilege at all.
 *
 * See docs/core-concepts/concurrency.md and docs/security/least-privilege.md.
 */
interface ChainLock
{
    /**
     * Run `$append` inside ONE transaction on the chain's connection, holding this
     * chain's append lock from the transaction's first statement until it ends.
     *
     * Exactly one attempt: no retry here. The caller owns the single retry ladder, and
     * a second, nested one (Laravel's `attempts`) is how contenders used to exhaust
     * their budget in lockstep. Throw whatever the database throws.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $append
     * @return TResult
     */
    public function withLock(ChainModels $models, ChainKey $key, Closure $append): mixed;
}
