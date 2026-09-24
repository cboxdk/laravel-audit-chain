<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Testing;

use Cbox\AuditChain\Codec\V1EntryCodec;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Exceptions\CannotCheckpointEmptyChain;
use Cbox\AuditChain\Models\AuditChainCheckpoint;
use Cbox\AuditChain\Models\AuditChainEntry;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\ChainVerification;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * An in-memory {@see AuditChain} for tests, in the spirit of `Event::fake()`: nothing
 * touches the database, and what was recorded can be asserted on.
 *
 * It is a real chain, just not a stored one: entries get their sequence, `prev_hash`
 * and `hash` from the same codec and the same hash composition as the database chain,
 * so code under test that reads `$entry->sequence` or `$entry->hash` sees values with
 * the right shape and linkage. The returned models are never saved.
 */
class FakeAuditChain implements AuditChain
{
    /**
     * Everything recorded, in order.
     *
     * @var list<array{key: ChainKey, event: ChainEvent, entry: ChainEntry}>
     */
    public array $recorded = [];

    /**
     * Every checkpoint taken, in order.
     *
     * @var list<array{key: ChainKey, checkpoint: ChainCheckpoint}>
     */
    public array $checkpoints = [];

    public function __construct(
        private readonly EntryCodec $codec = new V1EntryCodec,
    ) {}

    public function record(ChainKey $key, ChainEvent $event): ChainEntry
    {
        $previous = $this->last($key);

        $entry = new AuditChainEntry;
        $entry->assignChainKey($key);
        $entry->forceFill($event->columns);
        $entry->forceFill([
            'sequence' => $previous === null ? 1 : $previous->sequence + 1,
            'actor_type' => $event->actor->type,
            'actor_id' => $event->actor->id,
            'action' => $event->action,
            'target_type' => $event->targetType,
            'target_id' => $event->targetId,
            'context' => $event->context,
            'ip' => $event->ip,
            'recorded_at' => now(),
        ]);
        $entry->prev_hash = $previous === null ? DatabaseAuditChain::GENESIS_HASH : $previous->hash;
        $entry->hash = hash('sha256', $this->codec->canonicalize($entry).$entry->prev_hash);

        $this->recorded[] = ['key' => $key, 'event' => $event, 'entry' => $entry];

        return $entry;
    }

    /**
     * Always intact: the fake stores nothing that could have been tampered with. It
     * reports how many recorded entries fall in the window.
     */
    public function verify(ChainKey $key, int $fromSequence = 1, ?int $toSequence = null): ChainVerification
    {
        $count = 0;

        foreach ($this->entriesOf($key) as $entry) {
            if ($entry->sequence >= $fromSequence && ($toSequence === null || $entry->sequence <= $toSequence)) {
                $count++;
            }
        }

        return ChainVerification::valid($count);
    }

    public function head(ChainKey $key): int
    {
        $last = $this->last($key);

        return $last === null ? 0 : $last->sequence;
    }

    public function checkpoint(ChainKey $key): ChainCheckpoint
    {
        $head = $this->last($key);

        if ($head === null) {
            throw CannotCheckpointEmptyChain::forKey($key);
        }

        $checkpoint = new AuditChainCheckpoint;
        $checkpoint->assignChainKey($key);
        $checkpoint->forceFill([
            'up_to_sequence' => $head->sequence,
            'root_hash' => $head->hash,
            'signature' => 'fake',
        ]);

        $this->checkpoints[] = ['key' => $key, 'checkpoint' => $checkpoint];

        return $checkpoint;
    }

    /**
     * @param  (Closure(ChainEvent, ChainKey): bool)|null  $callback
     */
    public function assertRecorded(string $action, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($action, $callback),
            "Expected an audit event [{$action}] to be recorded, but none was.",
        );
    }

    public function assertRecordedOn(ChainKey $key, string $action): void
    {
        Assert::assertNotEmpty(
            $this->matching($action, static fn (ChainEvent $event, ChainKey $on): bool => $on->equals($key)),
            "Expected an audit event [{$action}] on chain [{$key->describe()}], but none was.",
        );
    }

    public function assertNotRecorded(string $action): void
    {
        Assert::assertEmpty($this->matching($action), "Did not expect an audit event [{$action}] to be recorded.");
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertEmpty($this->recorded, 'Expected no audit events to be recorded.');
    }

    public function assertRecordedCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded);
    }

    public function assertCheckpointed(?ChainKey $key = null): void
    {
        $matches = array_filter(
            $this->checkpoints,
            static fn (array $checkpoint): bool => $key === null || $checkpoint['key']->equals($key),
        );

        Assert::assertNotEmpty($matches, $key === null
            ? 'Expected a checkpoint to be taken, but none was.'
            : "Expected a checkpoint of chain [{$key->describe()}], but none was taken.");
    }

    /**
     * @return list<ChainEntry>
     */
    public function entriesOf(ChainKey $key): array
    {
        $entries = [];

        foreach ($this->recorded as $record) {
            if ($record['key']->equals($key)) {
                $entries[] = $record['entry'];
            }
        }

        return $entries;
    }

    /**
     * @param  (Closure(ChainEvent, ChainKey): bool)|null  $callback
     * @return list<ChainEvent>
     */
    private function matching(string $action, ?Closure $callback = null): array
    {
        $matches = [];

        foreach ($this->recorded as $record) {
            if ($record['event']->action === $action && ($callback === null || $callback($record['event'], $record['key']))) {
                $matches[] = $record['event'];
            }
        }

        return $matches;
    }

    private function last(ChainKey $key): ?ChainEntry
    {
        $entries = $this->entriesOf($key);

        return $entries === [] ? null : $entries[count($entries) - 1];
    }
}
