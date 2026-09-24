<?php

declare(strict_types=1);

use Cbox\AuditChain\Codec\V1EntryCodec;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Models\AuditChainEntry;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Carbon;

/*
 * KNOWN-ANSWER VECTORS FOR THE `audit-chain/v1` CANONICAL FORM.
 *
 * The expected strings below were written BY HAND from the format documented in
 * V1EntryCodec and docs/core-concepts/hash-format.md, and the expected digests were
 * computed from those literal strings with plain `hash('sha256', ...)` — not by running
 * the codec. So these are an independent statement of the format, and the codec is
 * held to it.
 *
 * The format is frozen: if one of these fails, the change broke every chain the codec
 * ever wrote. Do not "update the vector".
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function v1Entry(array $attributes): AuditChainEntry
{
    $entry = new AuditChainEntry;
    $entry->assignChainKey(ChainKey::of('tenant_a', 'ledger'));
    $entry->forceFill($attributes);

    return $entry;
}

it('produces the documented canonical bytes and digest for a full entry', function (): void {
    $entry = v1Entry([
        'sequence' => 1,
        'actor_type' => 'user',
        'actor_id' => 'usr_1',
        'action' => 'invoice.voided',
        'target_type' => 'invoice',
        'target_id' => 'inv_9',
        // Keys deliberately out of order at every depth; the list must keep its order.
        'context' => ['tags' => ['b', 'a'], 'reason' => 'duplicate', 'amount' => ['value' => 1200, 'currency' => 'DKK']],
        'ip' => '203.0.113.4',
        'recorded_at' => Carbon::parse('2026-09-01 10:00:00', 'UTC'),
    ]);

    $expected = '{"action":"invoice.voided","actor_id":"usr_1","actor_type":"user","codec":"audit-chain/v1","context":{"amount":{"currency":"DKK","value":1200},"reason":"duplicate","tags":["b","a"]},"ip":"203.0.113.4","partition":"tenant_a","recorded_at":1788256800,"scope":"ledger","sequence":1,"target_id":"inv_9","target_type":"invoice"}';

    $codec = new V1EntryCodec;

    expect($codec->canonicalize($entry))->toBe($expected)
        ->and(hash('sha256', $codec->canonicalize($entry).DatabaseAuditChain::GENESIS_HASH))
        ->toBe('f2e46583777a303251490fceb1d53f2249737b915f8e355d111ed70446029147');
});

it('writes nulls, unicode and slashes as documented, and hashes extra columns under "extra"', function (): void {
    $entry = v1Entry([
        'sequence' => 2,
        'actor_type' => 'system',
        'actor_id' => null,
        'action' => 'profile.renamed',
        'target_type' => null,
        'target_id' => null,
        'context' => ['url' => 'https://x.test/a/b', 'name' => 'Søren 東京'],
        'ip' => null,
        'recorded_at' => Carbon::parse('2026-09-01 10:01:01', 'UTC'),
        'request_id' => 'req_1',
    ]);

    $expected = '{"action":"profile.renamed","actor_id":null,"actor_type":"system","codec":"audit-chain/v1","context":{"name":"Søren 東京","url":"https://x.test/a/b"},"extra":{"request_id":"req_1"},"ip":null,"partition":"tenant_a","recorded_at":1788256861,"scope":"ledger","sequence":2,"target_id":null,"target_type":null}';

    $codec = new V1EntryCodec(['request_id']);

    expect($codec->canonicalize($entry))->toBe($expected)
        ->and(hash('sha256', $codec->canonicalize($entry).'f2e46583777a303251490fceb1d53f2249737b915f8e355d111ed70446029147'))
        ->toBe('5a03c77f4da02f5dd49f3f449379c4b6b7fdb94af20a5d1d8892dae474c406ac')
        ->and($codec->version())->toBe('audit-chain/v1')
        ->and($codec->extraColumns())->toBe(['request_id']);
});

it('reads an enum-cast actor type as its stored value', function (): void {
    $entry = new class extends AuditChainEntry
    {
        protected function casts(): array
        {
            return [...parent::casts(), 'actor_type' => TestActorType::class];
        }
    };

    $entry->forceFill(['actor_type' => TestActorType::Operator]);

    expect($entry->actorTypeValue())->toBe('operator');
});

enum TestActorType: string
{
    case Operator = 'operator';
}
