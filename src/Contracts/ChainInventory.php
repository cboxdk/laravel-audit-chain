<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\ValueObjects\ChainFilter;
use Cbox\AuditChain\ValueObjects\ChainHead;

/**
 * Lists the chains that exist in storage, with their heads and the highest sequence
 * each one's checkpoints attest.
 *
 * Reads storage directly rather than any registry of partitions or tenants: the set of
 * chains that exist is exactly the set with at least one entry.
 */
interface ChainInventory
{
    /**
     * Every chain with at least one entry, ordered by partition then scope.
     *
     * @return list<ChainHead>
     */
    public function heads(ChainFilter $filter = new ChainFilter): array;
}
