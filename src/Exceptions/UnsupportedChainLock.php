<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use LogicException;

/**
 * The configured lock strategy cannot run on this connection's database. Refused
 * rather than silently skipped: an append without its lock is still safe (the unique
 * key), but under contention it stops being live.
 */
class UnsupportedChainLock extends LogicException implements AuditChainException
{
    public static function forDriver(string $strategy, string $driver, string $supported): self
    {
        return new self("The [{$strategy}] audit-chain lock needs {$supported}; this chain's connection uses [{$driver}]. Use the anchor lock (audit-chain.lock.driver = anchor) or 'auto'.");
    }

    public static function unknown(string $driver): self
    {
        return new self("Unknown audit-chain.lock.driver [{$driver}]: use anchor, advisory or auto, or bind your own ChainLock.");
    }
}
