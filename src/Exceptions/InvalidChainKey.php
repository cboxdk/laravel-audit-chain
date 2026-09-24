<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use InvalidArgumentException;

class InvalidChainKey extends InvalidArgumentException implements AuditChainException
{
    public static function containsNul(string $part): self
    {
        return new self("A chain key's {$part} may not contain a NUL byte.");
    }
}
