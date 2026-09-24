<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

/**
 * Narrows a sweep over stored chains. An empty list means "all".
 */
readonly class ChainFilter
{
    /**
     * @param  list<string>  $partitions
     * @param  list<string>  $scopes
     */
    public function __construct(
        public array $partitions = [],
        public array $scopes = [],
    ) {}
}
