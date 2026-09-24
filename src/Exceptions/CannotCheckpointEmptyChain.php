<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use Cbox\AuditChain\ValueObjects\ChainKey;
use RuntimeException;

class CannotCheckpointEmptyChain extends RuntimeException implements AuditChainException
{
    public ?ChainKey $key = null;

    public static function forKey(ChainKey $key): self
    {
        $exception = new self("Cannot checkpoint audit chain [{$key->describe()}]: it has no entries yet.");
        $exception->key = $key;

        return $exception;
    }
}
