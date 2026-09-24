<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Checkpointer;

/**
 * What one chain's checkpoint pass did — returned rather than logged, so the caller
 * decides how loud to be, and so tests can assert on it without parsing output.
 *
 * A skipped chain is the NORMAL outcome, not a failure: a chain that has not been
 * appended to since its last checkpoint has nothing new to attest, and signing it
 * again would add a row that says exactly what the previous one already says.
 *
 * @see Checkpointer
 */
readonly class CheckpointOutcome
{
    public const ALREADY_AT_HEAD = 'already checkpointed at head';

    public function __construct(
        public ChainKey $key,
        /** The chain's head — the sequence a checkpoint would attest. */
        public int $headSequence,
        /** The highest sequence already attested by a stored checkpoint, if any. */
        public ?int $checkpointedSequence = null,
        /** The checkpoint row that was written; null on a dry run, a skip or a failure. */
        public ?string $checkpointId = null,
        public ?string $skippedReason = null,
        public ?string $failureReason = null,
    ) {}

    public static function signed(ChainKey $key, int $headSequence, ?int $checkpointedSequence, string $checkpointId): self
    {
        return new self($key, $headSequence, $checkpointedSequence, $checkpointId);
    }

    /** A dry run: this chain WOULD have been signed at `headSequence`. */
    public static function pending(ChainKey $key, int $headSequence, ?int $checkpointedSequence): self
    {
        return new self($key, $headSequence, $checkpointedSequence);
    }

    public static function skipped(ChainKey $key, int $headSequence, ?int $checkpointedSequence, string $reason): self
    {
        return new self($key, $headSequence, $checkpointedSequence, skippedReason: $reason);
    }

    /**
     * Signing this chain threw. Recorded per chain rather than thrown, so one
     * unsignable chain cannot stop the pass before the rest.
     */
    public static function failed(ChainKey $key, int $headSequence, ?int $checkpointedSequence, string $reason): self
    {
        return new self($key, $headSequence, $checkpointedSequence, failureReason: $reason);
    }

    public function wasSkipped(): bool
    {
        return $this->skippedReason !== null;
    }

    public function wasSigned(): bool
    {
        return $this->checkpointId !== null;
    }

    public function hasFailed(): bool
    {
        return $this->failureReason !== null;
    }
}
