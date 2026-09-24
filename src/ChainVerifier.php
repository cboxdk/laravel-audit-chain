<?php

declare(strict_types=1);

namespace Cbox\AuditChain;

use Cbox\AuditChain\Console\VerifyCommand;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\ValueObjects\ChainFilter;
use Cbox\AuditChain\ValueObjects\ChainHead;
use Cbox\AuditChain\ValueObjects\VerifiedChain;
use Throwable;

/**
 * Verifies every stored chain, or the last N entries of each.
 *
 * A full verification re-reads and re-hashes every row of every chain, which is the
 * right thing for an auditor and a slow thing for a nightly job on a large trail. A
 * window of the newest entries still checks their linkage back into the older ones,
 * and still cross-checks the newest checkpoint — so a scheduled windowed pass catches
 * recent tampering and truncation cheaply, and a periodic full pass covers the rest.
 *
 * @see VerifyCommand
 */
class ChainVerifier
{
    public function __construct(
        private readonly AuditChain $chain,
        private readonly ChainInventory $inventory,
        private readonly ChainContext $context,
    ) {}

    /**
     * @param  int|null  $window  verify only the newest N entries of each chain (null: all)
     * @return list<VerifiedChain>
     */
    public function verifyAll(ChainFilter $filter = new ChainFilter, ?int $window = null): array
    {
        return array_map(
            fn (ChainHead $head): VerifiedChain => $this->verifyChain($head, $window),
            $this->inventory->heads($filter),
        );
    }

    private function verifyChain(ChainHead $head, ?int $window): VerifiedChain
    {
        $from = $window === null ? 1 : max(1, $head->headSequence - max(1, $window) + 1);

        try {
            $verification = $this->context->run($head->key, fn () => $this->chain->verify($head->key, $from));
        } catch (Throwable $failure) {
            return new VerifiedChain($head->key, $head->headSequence, failure: $failure::class.': '.$failure->getMessage());
        }

        return new VerifiedChain($head->key, $head->headSequence, $verification);
    }
}
