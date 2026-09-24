<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use Cbox\AuditChain\ValueObjects\ChainKey;
use RuntimeException;
use Throwable;

/**
 * The append could not claim a free position in the chain within its attempt budget.
 *
 * Thrown rather than swallowed on purpose: an entry that cannot be written is a hole
 * in a tamper-evident trail, and a hole is indistinguishable from a deletion when
 * someone later verifies the chain. The caller must see it.
 */
class CannotAppendToChain extends RuntimeException implements AuditChainException
{
    public ?ChainKey $key = null;

    public int $attempts = 0;

    public static function forKey(ChainKey $key, int $attempts, Throwable $previous): self
    {
        $exception = new self(
            "Could not append to audit chain [{$key->describe()}]: the next sequence was taken by a concurrent writer on all {$attempts} attempts.",
            0,
            $previous,
        );

        $exception->key = $key;
        $exception->attempts = $attempts;

        return $exception;
    }
}
