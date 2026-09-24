<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * Runs work "as" a chain, for sweeps that touch many chains in one process
 * (the checkpoint and verify commands).
 *
 * The default just runs the callback. A host whose chains live under an ambient
 * context — a tenant, an environment, a per-tenant signing key — binds its own so
 * each chain is signed and verified from inside its own context, and the context is
 * restored afterwards.
 */
interface ChainContext
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(ChainKey $key, Closure $callback): mixed;
}
