<?php

declare(strict_types=1);

namespace Cbox\AuditChain;

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Enums\ChainBreak;
use Cbox\AuditChain\Exceptions\CannotAppendToChain;
use Cbox\AuditChain\Exceptions\CannotCheckpointEmptyChain;
use Cbox\AuditChain\Exceptions\CheckpointClaimsMalformed;
use Cbox\AuditChain\Exceptions\UnhashedColumn;
use Cbox\AuditChain\Locking\AnchorRowChainLock;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\ChainVerification;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The relational {@see AuditChain}: one table of entries, one of checkpoints, any
 * number of chains in each, addressed by (partition column, `scope`).
 *
 * Proven under real contention on PostgreSQL 16, MySQL 8.4 and MariaDB 11.8 (eight
 * forked writers x 100 appends to one chain: 800 written, 800 on disk, gapless, and
 * the chain verifies). The reasoning that got it there is written down at each step
 * below, because every part of it is load-bearing and none of it is obvious.
 */
class DatabaseAuditChain implements AuditChain
{
    use DetectsConcurrencyErrors;

    /**
     * The `prev_hash` of every chain's first entry.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * The chain position the default lock serialises on. See {@see AnchorRowChainLock}.
     */
    public const ANCHOR_SEQUENCE = AnchorRowChainLock::ANCHOR_SEQUENCE;

    /**
     * How many times an append may re-read the head and re-claim a position before
     * giving up. Only the first entry of a chain can collide more than once (there
     * is no anchor to queue on yet), and that resolves in a single extra round, so
     * the budget is a margin, not the mechanism.
     *
     * Eight covers one contender per writer in the measured 8-process case even in
     * the worst ordering, and every attempt after the first sleeps a jittered backoff.
     * The ladder also absorbs serialisation failures, which Laravel's own transaction
     * retry would otherwise spend on a private budget of three with no pause.
     */
    public const MAX_APPEND_ATTEMPTS = 8;

    /**
     * Ceiling, in milliseconds, on the jittered pause between append attempts.
     *
     * Retries are the resolution mechanism for a contended chain, so they must not
     * re-collide in lockstep: every retrier that woke from the same collision would
     * otherwise reach the insert at the same instant again.
     */
    public const MAX_BACKOFF_MILLISECONDS = 64;

    private readonly ChainLock $lock;

    /**
     * @param  ChainLock|null  $lock  how appenders serialise; the anchor-row lock when
     *                                omitted (the measured default)
     */
    public function __construct(
        private readonly ChainModels $models,
        private readonly EntryCodec $codec,
        private readonly CheckpointSigner $signer,
        private readonly CheckpointAnchor $anchor,
        ?ChainLock $lock = null,
    ) {
        $this->lock = $lock ?? new AnchorRowChainLock;
    }

    /**
     * Append one entry to the chain.
     *
     * Two writers must not compute the same next sequence, and the append must not
     * be lost when they try. Both are handled here, in that order:
     *
     * 1. Appenders to one chain serialise on the bound {@see ChainLock}, taken as
     *    the first statement of the attempt's transaction. The default,
     *    {@see AnchorRowChainLock}, locks the chain's ANCHOR row (sequence 1) rather
     *    than its head: under READ COMMITTED a blocked `FOR UPDATE` re-checks its
     *    predicate against the row it was waiting on, NOT against the
     *    `ORDER BY sequence DESC LIMIT 1` that picked it — so a waiter on the head
     *    wakes still holding the OLD head and computes a sequence that has just
     *    been taken. The anchor's predicate is an equality on the unique key, so it
     *    cannot go stale: whoever holds it reads the head afterwards and sees the
     *    previous holder's committed entry. Measured on PostgreSQL 16, 8 writers x
     *    100 appends went from 100 written / 700 lost to 800 written / 0 lost.
     *
     * 2. The unique(partition, scope, sequence) violation is caught and the append
     *    RE-READS the head and retries. This covers any window the lock cannot —
     *    for the anchor lock, the first entry of a chain, where there is no anchor
     *    to lock yet. It converges in one extra round — after the first winner
     *    commits, every retrier finds the anchor and queues on it.
     *
     * 3. A SERIALISATION FAILURE (SQLSTATE 40001 / deadlock) is retried on the same
     *    ladder, with a jittered pause. This is the genesis race on InnoDB: see
     *    AnchorRowChainLock::anchorId() for why an empty chain does not take a gap
     *    lock, and why a bounded retry is still kept behind that.
     *
     * A plain `transaction(..., attempts: 3)` does neither of the first two:
     * Laravel's concurrency detector matches SQLSTATE 40001 and deadlock messages,
     * and a duplicate key is 23505/23000 — so the transaction is never retried and
     * the entry is simply lost.
     *
     * Each attempt is a transaction of its own (`attempts: 1`) so that there is ONE
     * retry ladder rather than two nested ones. Laravel's own retry re-runs the
     * closure immediately, with no pause and no separate budget — which is exactly
     * how eight MariaDB writers spent all three attempts on the same gap.
     *
     * SCOPE OF THE RETRY. When record() is called inside a caller's transaction the
     * attempt is a savepoint, so a duplicate key still rolls back to it and retries
     * normally. A SERIALISATION failure there does not and must not: the engine has
     * already rolled the caller's whole transaction back, so Laravel raises a
     * DeadlockException (a PDOException, not a QueryException) and it passes straight
     * through this loop to the caller, who is the only one who can retry the unit of
     * work it belongs to.
     *
     * One thing no retry budget can fix: under REPEATABLE READ, an append nested in a
     * caller's transaction that has ALREADY read the entry table is answered from the
     * caller's fixed snapshot, so a colliding retry re-reads the same stale head and
     * collides again. That ends in CannotAppendToChain rather than a lost or
     * misplaced entry — the loud end of the trade, and the right one.
     */
    public function record(ChainKey $key, ChainEvent $event): ChainEntry
    {
        $this->guardColumns($event);

        for ($attempt = 1; ; $attempt++) {
            try {
                // One attempt = one transaction, opened by the lock strategy with the
                // chain's lock as its first statement. See ChainLock.
                return $this->lock->withLock($this->models, $key, fn (): ChainEntry => $this->appendOnce($key, $event));
            } catch (QueryException $collision) {
                if (! $this->isContention($collision)) {
                    throw $collision;
                }

                if ($attempt >= self::MAX_APPEND_ATTEMPTS) {
                    throw CannotAppendToChain::forKey($key, self::MAX_APPEND_ATTEMPTS, $collision);
                }

                $this->backOff($attempt);
            }
        }
    }

    public function head(ChainKey $key): int
    {
        $head = $this->models->entriesOf($key)->max('sequence');

        return is_numeric($head) ? (int) $head : 0;
    }

    public function verify(ChainKey $key, int $fromSequence = 1, ?int $toSequence = null): ChainVerification
    {
        $from = max(1, $fromSequence);

        $query = $this->models->entriesOf($key)
            ->where('sequence', '>=', $from)
            ->orderBy('sequence');

        if ($toSequence !== null) {
            $query->where('sequence', '<=', $toSequence);
        }

        $entries = $query->get();

        $expectedSequence = $from;
        $prevHash = self::GENESIS_HASH;

        if ($from > 1) {
            $before = $this->entryAt($key, $from - 1);
            $prevHash = $before === null ? self::GENESIS_HASH : $before->hash;
        }

        foreach ($entries as $entry) {
            if ($entry->sequence !== $expectedSequence) {
                return ChainVerification::broken($entry->sequence, ChainBreak::SequenceGap);
            }

            if (! hash_equals($prevHash, $entry->prev_hash)) {
                return ChainVerification::broken($entry->sequence, ChainBreak::LinkageMismatch);
            }

            if (! hash_equals($entry->hash, $this->hash($entry, $entry->prev_hash))) {
                return ChainVerification::broken($entry->sequence, ChainBreak::ContentMismatch);
            }

            $prevHash = $entry->hash;
            $expectedSequence++;
        }

        // Per-row/link integrity holds for the rows present — but that alone can't
        // detect entries deleted off the tail (or a wiped chain). Cross-check the
        // newest signed checkpoint: the entry it anchored must still be present with
        // the same hash.
        $anchorBreak = $this->verifyCheckpointAnchor($key);

        if ($anchorBreak !== null) {
            return $anchorBreak;
        }

        return ChainVerification::valid($entries->count());
    }

    public function checkpoint(ChainKey $key): ChainCheckpoint
    {
        $head = $this->headEntry($key);

        if ($head === null) {
            throw CannotCheckpointEmptyChain::forKey($key);
        }

        $claims = CheckpointClaims::forHead($key, $head->sequence, $head->hash, now()->getTimestamp());
        $signature = $this->signer->sign($claims);

        $checkpoint = $this->models->newCheckpoint();
        $checkpoint->assignChainKey($key);
        $checkpoint->forceFill([
            'up_to_sequence' => $head->sequence,
            'root_hash' => $head->hash,
            'signature' => $signature,
        ]);

        // The export runs inside the transaction that stores the row: an anchor that
        // throws rolls the row back, so the next pass signs and exports again instead
        // of skipping a head that was never anchored.
        return $this->models->checkpointConnection()->transaction(function () use ($checkpoint, $key, $claims, $signature): ChainCheckpoint {
            $checkpoint->save();

            $this->anchor->anchor(new SignedCheckpoint($key, $claims, $signature, $this->keyOf($checkpoint)));

            return $checkpoint;
        }, attempts: 1);
    }

    /**
     * The bytes an entry's hash covers, per the bound codec.
     */
    public function canonicalize(ChainEntry $entry): string
    {
        return $this->codec->canonicalize($entry);
    }

    /**
     * `SHA-256(canonical(entry) ‖ prev_hash)`, as lowercase hex.
     */
    public function hash(ChainEntry $entry, string $prevHash): string
    {
        return hash('sha256', $this->codec->canonicalize($entry).$prevHash);
    }

    /**
     * One append attempt, inside the transaction the lock strategy opened, after it
     * took the chain's lock. Every attempt re-reads the head — a retry that reused the
     * stale head would collide forever.
     *
     * The head read takes no lock of its own: the strategy's lock already excludes
     * every other appender on this chain, and where it cannot (the anchor strategy on an
     * empty chain) the unique key plus the retry in record() makes the read safe.
     */
    private function appendOnce(ChainKey $key, ChainEvent $event): ChainEntry
    {
        $last = $this->headEntry($key);

        if ($last === null) {
            $prevHash = self::GENESIS_HASH;
            $sequence = 1;
        } else {
            $prevHash = $last->hash;
            $sequence = $last->sequence + 1;
        }

        $entry = $this->models->newEntry();
        // Stamped explicitly, never left to a model hook: a hook that only fills the
        // partition when some ambient context is set would leave it NULL otherwise,
        // and a NULL partition makes the unique key inert (SQL treats NULLs as
        // distinct), so the chain silently stops being a chain.
        $entry->assignChainKey($key);
        // forceFill: the chain's columns are not the host's mass-assignment surface, and
        // a `$fillable` list on a host model must not be able to drop one silently.
        $entry->forceFill($event->columns);
        $entry->forceFill([
            'sequence' => $sequence,
            'actor_type' => $event->actor->type,
            'actor_id' => $event->actor->id,
            'action' => $event->action,
            'target_type' => $event->targetType,
            'target_id' => $event->targetId,
            'context' => $event->context,
            'ip' => $event->ip,
            'recorded_at' => now(),
        ]);
        $entry->prev_hash = $prevHash;
        $entry->hash = $this->hash($entry, $prevHash);
        $entry->save();

        return $entry;
    }

    /**
     * Whether a failed append lost a race and may be re-tried, as opposed to being
     * a real error (a bad column, a dead connection) that must reach the caller.
     *
     * Two shapes count, and only these two: another writer took the position
     * (duplicate key), or the engine picked this transaction as the victim of a
     * lock cycle (SQLSTATE 40001 / 1213). Everything else is re-thrown untouched —
     * retrying a malformed statement eight times just delays the same failure.
     */
    private function isContention(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || $this->causedByConcurrencyError($exception);
    }

    /**
     * Pause for a random, growing interval before the next attempt.
     *
     * Randomised on purpose: contenders that all woke from the same collision and
     * slept the same fixed time would simply collide again, together.
     */
    private function backOff(int $attempt): void
    {
        // Doubles per attempt, capped. A shift rather than `2 **` so the exponent
        // cannot run away into a float.
        $ceiling = min(1 << min($attempt, 8), self::MAX_BACKOFF_MILLISECONDS);

        usleep(random_int(0, $ceiling) * 1000);
    }

    /**
     * Detect deletion/truncation at or below the newest checkpoint by re-verifying
     * its signature and confirming the anchored entry is unchanged. Returns a broken
     * verification if violated, or null if there is nothing to contradict.
     */
    private function verifyCheckpointAnchor(ChainKey $key): ?ChainVerification
    {
        $checkpoint = $this->models->checkpointsOf($key)
            ->orderByDesc('up_to_sequence')
            ->first();

        if ($checkpoint === null) {
            return null;
        }

        try {
            $claims = $this->signer->verify($checkpoint->signature);
        } catch (CheckpointClaimsMalformed) {
            // The key signed it; it just is not a checkpoint claim set.
            return ChainVerification::broken($checkpoint->up_to_sequence, ChainBreak::CheckpointPayloadMismatch);
        } catch (Throwable) {
            return ChainVerification::broken($checkpoint->up_to_sequence, ChainBreak::CheckpointSignatureInvalid);
        }

        if ($claims->scope !== $key->scope
            || ($claims->partition !== null && $claims->partition !== $key->partition)
            || $claims->upToSequence !== $checkpoint->up_to_sequence
            || ! hash_equals($claims->rootHash, $checkpoint->root_hash)) {
            return ChainVerification::broken($checkpoint->up_to_sequence, ChainBreak::CheckpointPayloadMismatch);
        }

        $anchor = $this->entryAt($key, $checkpoint->up_to_sequence);

        if ($anchor === null || ! hash_equals($checkpoint->root_hash, $anchor->hash)) {
            return ChainVerification::broken($checkpoint->up_to_sequence, ChainBreak::CheckpointAnchorMissing);
        }

        return null;
    }

    /**
     * Refuse host columns the codec would not hash, and columns the chain owns.
     */
    private function guardColumns(ChainEvent $event): void
    {
        if ($event->columns === []) {
            return;
        }

        $declared = $this->codec->extraColumns();
        $owned = $this->models->newEntry()->chainColumns();

        foreach (array_keys($event->columns) as $column) {
            if (in_array($column, $owned, true)) {
                throw UnhashedColumn::reserved($column);
            }

            if (! in_array($column, $declared, true)) {
                throw UnhashedColumn::notDeclared($column, $this->codec->version(), $declared);
            }
        }
    }

    private function headEntry(ChainKey $key): ?ChainEntry
    {
        return $this->models->entriesOf($key)->orderByDesc('sequence')->first();
    }

    private function entryAt(ChainKey $key, int $sequence): ?ChainEntry
    {
        return $this->models->entriesOf($key)
            ->where('sequence', $sequence)
            ->first();
    }

    private function keyOf(ChainCheckpoint $checkpoint): string
    {
        $id = $checkpoint->getKey();

        return is_scalar($id) ? (string) $id : '';
    }
}
