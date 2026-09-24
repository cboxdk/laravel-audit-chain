<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Support;

use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Closure;

/**
 * The default {@see ChainContext}: chains need no ambient context, so it just runs.
 */
class PassthroughChainContext implements ChainContext
{
    public function run(ChainKey $key, Closure $callback): mixed
    {
        return $callback();
    }
}
