<?php

declare(strict_types=1);

use Cbox\AuditChain\Anchoring\NullCheckpointAnchor;
use Cbox\AuditChain\Codec\CanonicalJson;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Enums\ChainBreak;
use Cbox\AuditChain\Signing\Ed25519CheckpointSigner;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\Storage\DatabaseChainInventory;
use Cbox\AuditChain\Tests\Fixtures\LegacyCheckpoint;
use Cbox\AuditChain\Tests\Fixtures\LegacyEntry;
use Cbox\AuditChain\Tests\Fixtures\LegacyEntryCodec;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * ADOPTING A CHAIN SOMETHING ELSE WROTE, WITHOUT REWRITING A ROW.
 *
 * tests/Fixtures/legacy-chain.json holds real rows written by a different
 * implementation (laravel-id before it moved onto this package): its own table, its
 * own partition column, an extra hashed column, and a canonical form that is NOT this
 * package's v1. The only adaptation is a codec (LegacyEntryCodec) and two host models.
 *
 * If the package's append / verify core were subtly different from the one those rows
 * were written by — hash composition, genesis, prev-hash handling, the anchor
 * serialisation — these would fail.
 */

/**
 * @return array{rows: list<array<string, mixed>>, records: list<array{partition: string, at: string, event: array<string, mixed>, expect: array<string, mixed>}>}
 */
function legacyFixture(): array
{
    /** @var array{rows: list<array<string, mixed>>, records: list<array{partition: string, at: string, event: array<string, mixed>, expect: array<string, mixed>}>} $fixture */
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/legacy-chain.json'), true, flags: JSON_THROW_ON_ERROR);

    return $fixture;
}

function legacyChain(): DatabaseAuditChain
{
    return new DatabaseAuditChain(
        new ChainModels(LegacyEntry::class, LegacyCheckpoint::class),
        new LegacyEntryCodec,
        app(Ed25519CheckpointSigner::class),
        new NullCheckpointAnchor,
    );
}

/**
 * The chains present in the fixture, with their lengths.
 *
 * @return array<string, int>
 */
function legacyChains(): array
{
    $chains = [];

    foreach (legacyFixture()['rows'] as $row) {
        $id = $row['environment_id'].'|'.$row['scope'];
        $chains[$id] = max($chains[$id] ?? 0, (int) $row['sequence']);
    }

    return $chains;
}

beforeEach(function (): void {
    app()->instance(Ed25519CheckpointSigner::class, Ed25519CheckpointSigner::fromConfig());
});

it('verifies every stored legacy chain as written', function (): void {
    foreach (legacyFixture()['rows'] as $row) {
        DB::table('legacy_audit_logs')->insert($row);
    }

    expect(legacyChains())->toHaveCount(6);

    foreach (legacyChains() as $id => $length) {
        [$partition, $scope] = explode('|', $id);
        $key = ChainKey::of($partition, $scope);

        $result = legacyChain()->verify($key);

        expect($result->valid)->toBeTrue($id)
            ->and($result->verifiedCount)->toBe($length, $id)
            ->and(legacyChain()->head($key))->toBe($length, $id);
    }

    // ...and the codec is really covering the rows, not just agreeing with them.
    DB::table('legacy_audit_logs')->where('environment_id', '01J8ZK3V9Q4XW5T6Y7R8S9A0BC')
        ->where('scope', '01J8ZM0RGA0000000000000001')->where('sequence', 2)
        ->update(['organization_id' => '01J8ZM0RGB0000000000000002']);

    expect(legacyChain()->verify(ChainKey::of('01J8ZK3V9Q4XW5T6Y7R8S9A0BC', '01J8ZM0RGA0000000000000001'))->break)
        ->toBe(ChainBreak::ContentMismatch);
});

it('extends legacy chains with the exact hashes the old implementation produced', function (): void {
    foreach (legacyFixture()['records'] as $index => $record) {
        $this->travelTo(Carbon::parse($record['at']));

        /** @var array{action: string, actor_type: string, actor_id: string|null, organization_id: string|null, target_type: string|null, target_id: string|null, context_php: string, ip: string|null} $input */
        $input = $record['event'];
        /** @var array<string, mixed> $context */
        $context = unserialize($input['context_php'], ['allowed_classes' => false]);

        $entry = legacyChain()->record(
            ChainKey::of($record['partition'], $input['organization_id'] ?? '__system__'),
            (new ChainEvent(
                action: $input['action'],
                actor: ChainActor::of($input['actor_type'], $input['actor_id']),
                targetType: $input['target_type'],
                targetId: $input['target_id'],
                context: $context,
                ip: $input['ip'],
            ))->withColumns(['organization_id' => $input['organization_id']]),
        );

        expect($entry->sequence)->toBe($record['expect']['sequence'], "record #{$index}")
            ->and($entry->prev_hash)->toBe($record['expect']['prev_hash'], "record #{$index}")
            ->and((new LegacyEntryCodec)->canonicalize($entry))->toBe($record['expect']['canonical'], "record #{$index}")
            ->and($entry->hash)->toBe($record['expect']['hash'], "record #{$index}");
    }

    // The rebuilt table matches the stored one column for column (bar the random ids).
    // `context` is compared as written on SQLite; a server engine's JSON column may
    // re-serialise it (MySQL does — key order, spacing, escapes), which the codec's
    // canonicalisation absorbs, so there it is compared as the JSON value it holds.
    $raw = DB::connection()->getDriverName() === 'sqlite';

    $strip = static fn (array $rows): array => collect($rows)
        ->map(static function (array $row) use ($raw): array {
            unset($row['id']);
            $row['sequence'] = (int) $row['sequence'];

            if (! $raw) {
                $row['context'] = CanonicalJson::encode((array) json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR));
            }

            ksort($row);

            return $row;
        })
        ->sortBy(static fn (array $row): string => $row['environment_id'].'|'.$row['scope'].'|'.str_pad((string) $row['sequence'], 10, '0', STR_PAD_LEFT))
        ->values()->all();

    $rebuilt = DB::table('legacy_audit_logs')->get()->map(static fn (object $row): array => (array) $row)->all();

    expect($strip($rebuilt))->toBe($strip(legacyFixture()['rows']));
});

it('signs, sweeps and verifies checkpoints over the adopted chain with the package signer', function (): void {
    foreach (legacyFixture()['rows'] as $row) {
        DB::table('legacy_audit_logs')->insert($row);
    }

    $key = ChainKey::of('01J8ZK3V9Q4XW5T6Y7R8S9A0BC', '01J8ZM0RGA0000000000000001');

    $checkpoint = legacyChain()->checkpoint($key);

    expect($checkpoint->up_to_sequence)->toBe(5)
        ->and($checkpoint->getAttribute('organization_id'))->toBe('01J8ZM0RGA0000000000000001')
        ->and(legacyChain()->verify($key)->valid)->toBeTrue();

    $heads = (new DatabaseChainInventory(new ChainModels(LegacyEntry::class, LegacyCheckpoint::class)))->heads();

    expect($heads)->toHaveCount(6)
        ->and(collect($heads)->first(fn ($head): bool => $head->key->equals($key))?->attestedSequence)->toBe(5);

    DB::table('legacy_audit_logs')->where('environment_id', $key->partition)->where('scope', $key->scope)->where('sequence', 5)->delete();

    expect(legacyChain()->verify($key)->break)->toBe(ChainBreak::CheckpointAnchorMissing);
});

/**
 * A signer adapting an older token format may not have bound the partition into what
 * it signed. Its claims come back with `partition: null`, and verification then holds
 * the scope, sequence and root hash to the row — and nothing is loosened beyond that.
 */
it('accepts partition-less claims from an adapting signer, and still checks everything else', function (): void {
    foreach (legacyFixture()['rows'] as $row) {
        DB::table('legacy_audit_logs')->insert($row);
    }

    $signer = new class implements CheckpointSigner
    {
        public function sign(CheckpointClaims $claims): string
        {
            // An "older format" that never carried the partition.
            return json_encode([$claims->scope, $claims->upToSequence, $claims->rootHash, $claims->issuedAt], JSON_THROW_ON_ERROR);
        }

        public function verify(string $token): CheckpointClaims
        {
            /** @var array{string, int, string, int} $parts */
            $parts = json_decode($token, true, flags: JSON_THROW_ON_ERROR);

            return new CheckpointClaims($parts[0], $parts[1], $parts[2], $parts[3]);
        }
    };

    $chain = new DatabaseAuditChain(new ChainModels(LegacyEntry::class, LegacyCheckpoint::class), new LegacyEntryCodec, $signer, new NullCheckpointAnchor);
    $key = ChainKey::of('01J8ZK3V9Q4XW5T6Y7R8S9A0BC', '01J8ZM0RGA0000000000000001');

    $chain->checkpoint($key);

    expect($chain->verify($key)->valid)->toBeTrue();

    // A token claiming another scope is still refused.
    DB::table('legacy_audit_checkpoints')->update([
        'signature' => json_encode(['another-scope', 5, DB::table('legacy_audit_checkpoints')->value('root_hash'), 1]),
    ]);

    expect($chain->verify($key)->break)->toBe(ChainBreak::CheckpointPayloadMismatch);
});
