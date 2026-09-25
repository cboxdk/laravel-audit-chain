<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Locking;

use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * `audit-chain.lock.driver = auto`: the PostgreSQL advisory lock on PostgreSQL, the
 * anchor-row lock everywhere else.
 *
 * Decided per call from the chain's own connection, so one app can hold chains on
 * different engines. On PostgreSQL this is the strategy a least-privilege runtime role
 * (SELECT and INSERT only) needs. On MariaDB the anchor lock already needs nothing more.
 * On MySQL 8 the anchor lock still needs UPDATE on one column — see
 * docs/security/least-privilege.md.
 */
class AutoChainLock implements ChainLock
{
    public function __construct(
        private readonly ChainLock $postgres = new PostgresAdvisoryChainLock,
        private readonly ChainLock $otherwise = new AnchorRowChainLock,
    ) {}

    public function withLock(ChainModels $models, ChainKey $key, Closure $append): mixed
    {
        $strategy = $models->connection()->getDriverName() === 'pgsql' ? $this->postgres : $this->otherwise;

        return $strategy->withLock($models, $key, $append);
    }
}
