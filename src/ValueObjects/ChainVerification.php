<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Enums\ChainBreak;

/**
 * The result of verifying a chain, or a window of one.
 *
 * `new ChainVerification` is a valid, empty result — "nothing in range, nothing
 * broken" — so a test double can return one without ceremony.
 *
 * A valid result says the rows PRESENT in the range are intact and linked, and that
 * the newest signed checkpoint still matches the chain. It does not say every event
 * that happened was recorded: the chain proves integrity, not completeness.
 */
readonly class ChainVerification
{
    /** Mirrors `$break?->value`, for callers that only want the sentence. */
    public ?string $reason;

    public function __construct(
        public bool $valid = true,
        public int $verifiedCount = 0,
        public ?int $brokenAtSequence = null,
        public ?ChainBreak $break = null,
    ) {
        $this->reason = $break?->value;
    }

    public static function valid(int $verifiedCount): self
    {
        return new self(true, $verifiedCount);
    }

    public static function broken(int $sequence, ChainBreak $break): self
    {
        return new self(false, 0, $sequence, $break);
    }
}
