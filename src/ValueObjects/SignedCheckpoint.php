<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Contracts\CheckpointAnchor;

/**
 * A checkpoint as handed to a {@see CheckpointAnchor}: which chain, what was attested,
 * the signed token, and the id of the row it was stored under.
 */
readonly class SignedCheckpoint
{
    public function __construct(
        public ChainKey $key,
        public CheckpointClaims $claims,
        public string $token,
        public string $checkpointId,
    ) {}
}
