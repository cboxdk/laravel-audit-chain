<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\ChainVerifier;

/**
 * One chain's verdict from a {@see ChainVerifier} sweep. `failure` is set when the
 * verification itself could not run (as opposed to running and finding a break).
 */
readonly class VerifiedChain
{
    public function __construct(
        public ChainKey $key,
        public int $headSequence,
        public ?ChainVerification $verification = null,
        public ?string $failure = null,
    ) {}

    public function isIntact(): bool
    {
        return $this->failure === null && $this->verification !== null && $this->verification->valid;
    }
}
