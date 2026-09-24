<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use LogicException;

class InvalidChainModel extends LogicException implements AuditChainException
{
    public static function mustExtend(string $given, string $base): self
    {
        return new self("Audit chain model [{$given}] must extend [{$base}].");
    }

    public static function unexpectedInstance(string $expected, string $given): self
    {
        return new self("Expected the audit chain to produce a [{$expected}], got [{$given}]. Check the chain's configured models.");
    }
}
