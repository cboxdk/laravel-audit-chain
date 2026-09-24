<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A checkpoint token did not verify: malformed envelope, unknown key id, or a
 * signature that does not match. Verification reports the checkpoint as untrusted.
 */
class CheckpointSignatureInvalid extends RuntimeException implements AuditChainException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('Checkpoint signature failed to verify: '.$reason, 0, $previous);
    }
}
