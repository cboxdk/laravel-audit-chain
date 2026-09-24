<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Exceptions\CannotCheckpointEmptyChain;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The fake, dogfooded through the trait the package ships (composed in TestCase).
 */

it('records without touching the database and asserts on what was recorded', function (): void {
    $audit = $this->fakeAuditChain();
    $key = ChainKey::of('tenant_a', 'ledger');

    app(AuditChain::class)->record($key, ChainEvent::by(ChainActor::of('user', 'usr_1'), 'invoice.voided', ['reason' => 'dup']));

    $audit->assertRecorded('invoice.voided');
    $audit->assertRecorded('invoice.voided', fn (ChainEvent $event, ChainKey $on): bool => $event->actor->id === 'usr_1' && $on->equals($key));
    $audit->assertRecordedOn($key, 'invoice.voided');
    $audit->assertNotRecorded('invoice.paid');
    $audit->assertRecordedCount(1);

    expect(DB::table('audit_chain_entries')->count())->toBe(0);
});

it('chains what it records, with real hashes and linkage', function (): void {
    $audit = $this->fakeAuditChain();
    $key = ChainKey::of('tenant_a', 'ledger');

    $first = $audit->record($key, ChainEvent::system('a'));
    $second = $audit->record($key, ChainEvent::system('b'));
    $other = $audit->record(ChainKey::of('tenant_b', 'ledger'), ChainEvent::system('c'));

    expect($first->sequence)->toBe(1)
        ->and($first->prev_hash)->toBe(DatabaseAuditChain::GENESIS_HASH)
        ->and($second->sequence)->toBe(2)
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($other->sequence)->toBe(1)
        ->and($audit->head($key))->toBe(2)
        ->and($audit->verify($key)->verifiedCount)->toBe(2)
        ->and($audit->verify($key, fromSequence: 2)->verifiedCount)->toBe(1)
        ->and($first->exists)->toBeFalse();
});

it('checkpoints in memory and asserts on it', function (): void {
    $audit = $this->fakeAuditChain();
    $key = ChainKey::of('tenant_a', 'ledger');

    expect(fn () => $audit->checkpoint($key))->toThrow(CannotCheckpointEmptyChain::class);

    $entry = $audit->record($key, ChainEvent::system('a'));
    $checkpoint = $audit->checkpoint($key);

    expect($checkpoint->root_hash)->toBe($entry->hash)
        ->and($checkpoint->up_to_sequence)->toBe(1);

    $audit->assertCheckpointed();
    $audit->assertCheckpointed($key);

    expect(fn () => $audit->assertCheckpointed(ChainKey::of('other', 'chain')))->toThrow(AssertionFailedError::class);
});

it('fails its assertions when they do not hold', function (): void {
    $audit = $this->fakeAuditChain();

    $audit->assertNothingRecorded();

    expect(fn () => $audit->assertRecorded('never'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $audit->assertCheckpointed())->toThrow(AssertionFailedError::class);
});
