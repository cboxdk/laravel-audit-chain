<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Anchoring;

use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;

/**
 * Anchors nothing. The default, because where to export is a deployment decision the
 * package cannot make — but without an external copy, checkpoints only prove what an
 * attacker who can rewrite the database has not bothered to rewrite. Configure a real
 * anchor (`audit-chain.anchor.driver = filesystem`) in production.
 */
class NullCheckpointAnchor implements CheckpointAnchor
{
    public function anchor(SignedCheckpoint $checkpoint): void {}
}
