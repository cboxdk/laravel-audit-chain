<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

/**
 * One stored chain as an inventory sees it: its key, the sequence of its newest
 * entry, and the highest sequence any stored checkpoint attests (null: never
 * checkpointed).
 */
readonly class ChainHead
{
    public function __construct(
        public ChainKey $key,
        public int $headSequence,
        public ?int $attestedSequence = null,
    ) {}

    public function isAttestedAtHead(): bool
    {
        return $this->attestedSequence !== null && $this->attestedSequence >= $this->headSequence;
    }
}
