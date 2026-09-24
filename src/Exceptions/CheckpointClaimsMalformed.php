<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use RuntimeException;

/**
 * The token's signature verified, but what it signed is not a well-formed checkpoint
 * claim set. Verification reports this as a payload mismatch, not a bad signature:
 * the key did sign it, it just does not say what a checkpoint must say.
 */
class CheckpointClaimsMalformed extends RuntimeException implements AuditChainException
{
    public static function because(string $reason): self
    {
        return new self('Checkpoint claims are malformed: '.$reason);
    }
}
