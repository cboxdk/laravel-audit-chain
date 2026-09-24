<?php

declare(strict_types=1);

namespace Cbox\AuditChain;

use Cbox\AuditChain\Console\CheckpointCommand;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\ValueObjects\ChainFilter;
use Cbox\AuditChain\ValueObjects\ChainHead;
use Cbox\AuditChain\ValueObjects\CheckpointOutcome;
use Throwable;

/**
 * Signs a checkpoint over every chain that has advanced since its last one.
 *
 * ## What a checkpoint is for
 *
 * The hash chain detects MODIFICATION and sequence GAPS on its own. It cannot detect
 * TRUNCATION: delete the newest N entries and what remains is a shorter, perfectly
 * valid chain. {@see AuditChain::verify()} closes that hole only through a signed
 * checkpoint — the entry a checkpoint attests must still be present with the same hash
 * — so a chain with NO checkpoints has no tail-deletion detection at all.
 *
 * ## Why it is not scheduled by default
 *
 * `audit-chain.checkpoint.schedule` defaults to FALSE. Signing the first checkpoint is
 * a ONE-WAY DOOR: from that moment the chain's current hashes are attested by a
 * signature that may already have been exported to an append-only store, and any
 * later re-chaining (a codec change, hashing ciphertext instead of plaintext so that
 * personal data can be crypto-shredded) would make every retained checkpoint report
 * tampering that did not happen. Decide whether a re-chain is ahead of you FIRST;
 * then turn the schedule on. A deployment with no such migration ahead of it should
 * turn it on right away — until then nothing detects a truncated trail.
 *
 * ## Safety
 *
 * Idempotent: a chain whose head is already attested is skipped, so re-running adds
 * nothing. Safe alongside live appends: it takes no lock and writes no entry, and an
 * append that lands mid-pass simply belongs to the next checkpoint. Two overlapping
 * passes can at worst sign the same head twice, which is harmless — verification reads
 * the highest checkpoint.
 *
 * Each chain is signed from inside {@see ChainContext::run()}, so a host whose signing
 * keys or row ownership follow an ambient tenant signs each chain as its own tenant.
 *
 * @see CheckpointCommand
 */
class Checkpointer
{
    public function __construct(
        private readonly AuditChain $chain,
        private readonly ChainInventory $inventory,
        private readonly ChainContext $context,
    ) {}

    /**
     * Checkpoint every chain the filter selects, ordered by partition then scope.
     *
     * @return list<CheckpointOutcome>
     */
    public function checkpointAll(bool $dryRun = false, bool $force = false, ChainFilter $filter = new ChainFilter): array
    {
        return array_map(
            fn (ChainHead $head): CheckpointOutcome => $this->checkpointChain($head, $dryRun, $force),
            $this->inventory->heads($filter),
        );
    }

    private function checkpointChain(ChainHead $head, bool $dryRun, bool $force): CheckpointOutcome
    {
        // Nothing appended since the last checkpoint: another signature over the same
        // head would attest exactly what the previous one already attests.
        if (! $force && $head->isAttestedAtHead()) {
            return CheckpointOutcome::skipped($head->key, $head->headSequence, $head->attestedSequence, CheckpointOutcome::ALREADY_AT_HEAD);
        }

        if ($dryRun) {
            return CheckpointOutcome::pending($head->key, $head->headSequence, $head->attestedSequence);
        }

        try {
            $checkpoint = $this->context->run($head->key, fn () => $this->chain->checkpoint($head->key));
        } catch (Throwable $failure) {
            // Recorded, not thrown: a deployment can have one chain per tenant, and a
            // single unsignable one (a missing signing key for its tenant, say) must
            // not stop every chain after it. The command prints the reason and exits
            // non-zero, so it is still loud.
            return CheckpointOutcome::failed($head->key, $head->headSequence, $head->attestedSequence, $failure::class.': '.$failure->getMessage());
        }

        $id = $checkpoint->getKey();

        return CheckpointOutcome::signed($head->key, $checkpoint->up_to_sequence, $head->attestedSequence, is_scalar($id) ? (string) $id : '');
    }
}
