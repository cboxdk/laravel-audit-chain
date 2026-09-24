<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use InvalidArgumentException;

class InvalidChainEvent extends InvalidArgumentException implements AuditChainException
{
    public static function emptyAction(): self
    {
        return new self('An audit event needs an action.');
    }

    public static function emptyActorType(): self
    {
        return new self('An audit actor needs a type.');
    }
}
