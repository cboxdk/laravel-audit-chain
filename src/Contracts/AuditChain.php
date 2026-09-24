<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\Exceptions\CannotAppendToChain;
use Cbox\AuditChain\Exceptions\CannotCheckpointEmptyChain;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\ChainVerification;

/**
 * An append-only, hash-chained audit trail made of independent chains, each addressed
 * by a {@see ChainKey}.
 *
 * Each entry chains to the previous one in its chain:
 *
 *     hash = SHA-256( canonical(entry) ‖ prev_hash )
 *
 * so any later change to a recorded entry, a gap, or a reordering is detectable by
 * {@see verify()}. Truncation — deleting the newest entries — is not detectable from
 * the chain alone; that is what {@see checkpoint()} is for.
 *
 * Honest scope: this is tamper-EVIDENT, not tamper-proof. Anyone who can rewrite the
 * whole table can recompute every hash. Real anti-tamper rests on signed checkpoints
 * exported to a store the database's writers cannot reach. And the chain proves
 * INTEGRITY, not COMPLETENESS: it cannot know about an event nobody recorded.
 */
interface AuditChain
{
    /**
     * Append an event to the chain `key` addresses, and return the stored entry.
     *
     * @throws CannotAppendToChain when concurrent writers took every position it tried
     */
    public function record(ChainKey $key, ChainEvent $event): ChainEntry;

    /**
     * Verify a window of one chain: recompute each entry's hash, check sequence
     * continuity and prev-hash linkage, and cross-check the newest signed checkpoint.
     *
     * A window that starts past 1 takes its first prev-hash from the stored entry
     * before it, so linkage is checked, not assumed.
     */
    public function verify(ChainKey $key, int $fromSequence = 1, ?int $toSequence = null): ChainVerification;

    /**
     * The sequence of the newest entry in the chain, or 0 when it has none.
     *
     * Exists so a caller can verify a WINDOW (the last N entries) instead of all of
     * history on every render.
     */
    public function head(ChainKey $key): int;

    /**
     * Sign the chain's current head, store the checkpoint, and hand it to the bound
     * {@see CheckpointAnchor}.
     *
     * @throws CannotCheckpointEmptyChain
     */
    public function checkpoint(ChainKey $key): ChainCheckpoint;
}
