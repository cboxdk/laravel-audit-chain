<?php

declare(strict_types=1);

use Cbox\AuditChain\Codec\V1EntryCodec;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Enums\ChainBreak;
use Cbox\AuditChain\Exceptions\UnhashedColumn;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;

/*
 * A host column on the entry table (a tenant id, a request id) is written verbatim —
 * and only when the codec hashes it. A column stored but not hashed is a column that
 * can be rewritten without the chain noticing, so it is refused before anything is
 * written, never stored silently.
 */

// `request_id` is added to the entry table by tests/Fixtures/migrations.

it('refuses a column the codec does not hash, and writes nothing', function (): void {
    $event = ChainEvent::system('x')->withColumns(['request_id' => 'req_1']);

    expect(fn () => app(AuditChain::class)->record(ChainKey::of('p', 's'), $event))
        ->toThrow(UnhashedColumn::class, 'does not hash it');

    expect(DB::table('audit_chain_entries')->count())->toBe(0);
});

it('refuses a column the chain owns', function (): void {
    app()->instance(EntryCodec::class, new V1EntryCodec(['sequence']));
    app()->forgetInstance(AuditChain::class);

    $event = ChainEvent::system('x')->withColumns(['sequence' => 99]);

    expect(fn () => app(AuditChain::class)->record(ChainKey::of('p', 's'), $event))
        ->toThrow(UnhashedColumn::class, 'maintained by the chain');
});

it('writes a declared column and puts it inside the hash', function (): void {
    config(['audit-chain.codec.extra_columns' => ['request_id']]);
    app()->forgetInstance(EntryCodec::class);
    app()->forgetInstance(AuditChain::class);

    $key = ChainKey::of('p', 's');
    $entry = app(AuditChain::class)->record($key, ChainEvent::system('x')->withColumns(['request_id' => 'req_1']));

    expect(DB::table('audit_chain_entries')->value('request_id'))->toBe('req_1')
        ->and(app(EntryCodec::class)->canonicalize($entry))->toContain('"extra":{"request_id":"req_1"}')
        ->and(app(AuditChain::class)->verify($key)->valid)->toBeTrue();

    DB::table('audit_chain_entries')->update(['request_id' => 'req_forged']);

    expect(app(AuditChain::class)->verify($key)->break)->toBe(ChainBreak::ContentMismatch);
});
