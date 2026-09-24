<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Enums\ChainBreak;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;

function chain(): AuditChain
{
    return app(AuditChain::class);
}

it('appends the first entry of a chain to genesis', function (): void {
    $entry = chain()->record(ChainKey::of('tenant_a', 'ledger'), ChainEvent::system('ledger.opened'));

    expect($entry->exists)->toBeTrue()
        ->and($entry->sequence)->toBe(1)
        ->and($entry->prev_hash)->toBe(DatabaseAuditChain::GENESIS_HASH)
        ->and($entry->hash)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($entry->chainKey()->equals(ChainKey::of('tenant_a', 'ledger')))->toBeTrue();
});

it('links each entry to the one before it', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    $first = chain()->record($key, ChainEvent::system('a'));
    $second = chain()->record($key, ChainEvent::system('b'));

    expect($second->sequence)->toBe(2)
        ->and($second->prev_hash)->toBe($first->hash);
});

it('keeps an independent chain per key — per scope AND per partition', function (): void {
    chain()->record(ChainKey::of('tenant_a', 'x'), ChainEvent::system('one'));
    chain()->record(ChainKey::of('tenant_a', 'y'), ChainEvent::system('two'));
    chain()->record(ChainKey::of('tenant_b', 'x'), ChainEvent::system('three'));
    $again = chain()->record(ChainKey::of('tenant_a', 'x'), ChainEvent::system('four'));

    expect($again->sequence)->toBe(2)
        ->and(chain()->head(ChainKey::of('tenant_a', 'y')))->toBe(1)
        ->and(chain()->head(ChainKey::of('tenant_b', 'x')))->toBe(1)
        ->and(chain()->head(ChainKey::of('tenant_c', 'x')))->toBe(0);
});

it('stores every field of the event', function (): void {
    $entry = chain()->record(
        ChainKey::of('tenant_a', 'ledger'),
        (new ChainEvent(
            action: 'invoice.voided',
            actor: ChainActor::of('user', 'usr_1'),
            context: ['reason' => 'duplicate', 'amount' => ['value' => 1200, 'currency' => 'DKK']],
        ))->on('invoice', 'inv_9')->from('203.0.113.4'),
    );

    $stored = $entry->fresh();

    expect($stored)->not->toBeNull()
        ->and($stored?->action)->toBe('invoice.voided')
        ->and($stored?->actorTypeValue())->toBe('user')
        ->and($stored?->actor_id)->toBe('usr_1')
        ->and($stored?->target_type)->toBe('invoice')
        ->and($stored?->target_id)->toBe('inv_9')
        ->and($stored?->ip)->toBe('203.0.113.4')
        ->and($stored?->context)->toBe(['reason' => 'duplicate', 'amount' => ['value' => 1200, 'currency' => 'DKK']])
        ->and($stored?->chainPartition())->toBe('tenant_a')
        ->and($stored?->scope)->toBe('ledger');
});

it('verifies an intact chain', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    foreach (['a', 'b', 'c'] as $action) {
        chain()->record($key, ChainEvent::system($action));
    }

    $result = chain()->verify($key);

    expect($result->valid)->toBeTrue()
        ->and($result->verifiedCount)->toBe(3)
        ->and($result->brokenAtSequence)->toBeNull()
        ->and($result->break)->toBeNull()
        ->and($result->reason)->toBeNull();
});

it('reports an empty chain as valid with nothing verified', function (): void {
    $result = chain()->verify(ChainKey::of('nobody', 'nothing'));

    expect($result->valid)->toBeTrue()->and($result->verifiedCount)->toBe(0);
});

/**
 * Every stored column that goes into the hash, one at a time. A forged value must
 * DIFFER from the original, or the hash is unchanged and the test proves nothing.
 */
it('detects tampering with any hashed column', function (string $column, mixed $forged, int $breaksAt, ChainBreak $break): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    foreach (['a', 'b', 'c'] as $action) {
        chain()->record($key, (new ChainEvent(
            action: $action,
            actor: ChainActor::of('user', 'actor_original'),
            context: ['reason' => 'original'],
        ))->on('user', 'target_original')->from('198.51.100.7'));
    }

    DB::table('audit_chain_entries')->where('scope', 'ledger')->where('sequence', 2)->update([$column => $forged]);

    $result = chain()->verify($key);

    expect($result->valid)->toBeFalse("{$column} is not covered by the chain")
        ->and($result->brokenAtSequence)->toBe($breaksAt)
        ->and($result->break)->toBe($break);
})->with([
    'action' => ['action', 'forged', 2, ChainBreak::ContentMismatch],
    'actor_type' => ['actor_type', 'system', 2, ChainBreak::ContentMismatch],
    'actor_id' => ['actor_id', 'actor_forged', 2, ChainBreak::ContentMismatch],
    'target_type' => ['target_type', 'organization', 2, ChainBreak::ContentMismatch],
    'target_id' => ['target_id', 'target_forged', 2, ChainBreak::ContentMismatch],
    'context' => ['context', '{"reason":"forged"}', 2, ChainBreak::ContentMismatch],
    'ip' => ['ip', '203.0.113.9', 2, ChainBreak::ContentMismatch],
    'recorded_at' => ['recorded_at', '2020-01-01 00:00:00', 2, ChainBreak::ContentMismatch],
    'hash' => ['hash', str_repeat('f', 64), 2, ChainBreak::ContentMismatch],
    'prev_hash' => ['prev_hash', str_repeat('e', 64), 2, ChainBreak::LinkageMismatch],

    // These two ADDRESS the chain, so rewriting one moves the row out of the chain being
    // read. The break surfaces at the next entry, whose prev_hash no longer names
    // anything present — which is what makes a row unmovable between chains.
    'partition' => ['partition_key', 'tenant_forged', 3, ChainBreak::SequenceGap],
    'scope' => ['scope', 'forged_scope', 3, ChainBreak::SequenceGap],
]);

it('detects an entry moved into another chain, from that chain too', function (): void {
    $a = ChainKey::of('tenant_a', 'ledger');
    $b = ChainKey::of('tenant_b', 'ledger');

    $moved = chain()->record($a, ChainEvent::system('a'));

    // An empty target chain: the moved row is the whole of it, at sequence 1, linked to
    // genesis. Only the partition inside the hash gives it away.
    DB::table('audit_chain_entries')->where('id', $moved->id)->update(['partition_key' => 'tenant_b']);

    $result = chain()->verify($b);

    expect($result->valid)->toBeFalse()
        ->and($result->break)->toBe(ChainBreak::ContentMismatch);
});

it('detects a deleted entry as a gap', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    foreach (['a', 'b', 'c'] as $action) {
        chain()->record($key, ChainEvent::system($action));
    }

    DB::table('audit_chain_entries')->where('sequence', 2)->delete();

    $result = chain()->verify($key);

    expect($result->valid)->toBeFalse()
        ->and($result->brokenAtSequence)->toBe(3)
        ->and($result->break)->toBe(ChainBreak::SequenceGap);
});

it('verifies a window anchored to the entry before it', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    foreach (range(1, 5) as $i) {
        chain()->record($key, ChainEvent::system('e'.$i));
    }

    $window = chain()->verify($key, fromSequence: chain()->head($key) - 1);

    expect($window->valid)->toBeTrue()->and($window->verifiedCount)->toBe(2);

    $bounded = chain()->verify($key, fromSequence: 2, toSequence: 3);

    expect($bounded->valid)->toBeTrue()->and($bounded->verifiedCount)->toBe(2);

    // The window's first prev-hash comes from the stored entry before it — so a
    // rewritten link INTO the window is caught, not assumed.
    DB::table('audit_chain_entries')->where('sequence', 4)->update(['prev_hash' => str_repeat('a', 64)]);

    expect(chain()->verify($key, fromSequence: 4)->break)->toBe(ChainBreak::LinkageMismatch);
});

it('hashes context independently of key order, but not of list order', function (): void {
    $one = ChainKey::of('p', 'one');
    $two = ChainKey::of('p', 'two');

    $this->freezeSecond();

    $a = chain()->record($one, ChainEvent::system('x', ['b' => 1, 'a' => ['d' => 2, 'c' => 3]]));
    $b = chain()->record($two, ChainEvent::system('x', ['a' => ['c' => 3, 'd' => 2], 'b' => 1]));

    $codec = app(EntryCodec::class);

    expect(str_replace('"scope":"one"', '"scope":"two"', $codec->canonicalize($a)))->toBe($codec->canonicalize($b));

    $c = chain()->record(ChainKey::of('p', 'three'), ChainEvent::system('x', ['list' => [1, 2]]));
    $d = chain()->record(ChainKey::of('p', 'four'), ChainEvent::system('x', ['list' => [2, 1]]));

    expect(str_replace('"scope":"three"', '"scope":"four"', $codec->canonicalize($c)))->not->toBe($codec->canonicalize($d));
});
