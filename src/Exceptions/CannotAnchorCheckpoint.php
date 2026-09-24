<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exporting a signed checkpoint to its external store failed. The checkpoint row is
 * rolled back with it, so the next pass signs and exports again rather than skipping
 * a head that was never anchored.
 */
class CannotAnchorCheckpoint extends RuntimeException implements AuditChainException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('Could not anchor checkpoint: '.$reason, 0, $previous);
    }

    public static function conflictingCopy(string $path): self
    {
        return new self("Refusing to overwrite anchored checkpoint [{$path}]: a different document is already stored there. An anchor is append-only.");
    }
}
