<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\Exceptions\CannotAnchorCheckpoint;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;

/**
 * Exports a signed checkpoint to a store OUTSIDE the database the chain lives in.
 *
 * This is what turns tamper-evidence into something an attacker with database write
 * access cannot quietly undo: they can rewrite rows and even checkpoint rows, but not
 * the copies already exported to an append-only store they cannot reach (an object
 * bucket with object lock, a WORM volume, a transparency log).
 *
 * Called inside the transaction that stores the checkpoint row: throwing rolls the
 * row back, so a checkpoint is never recorded as done while its export failed.
 * Implementations must be idempotent for the same checkpoint and must never overwrite
 * a different one.
 */
interface CheckpointAnchor
{
    /**
     * @throws CannotAnchorCheckpoint
     */
    public function anchor(SignedCheckpoint $checkpoint): void;
}
