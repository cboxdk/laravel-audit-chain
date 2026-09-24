<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Enums\ChainBreak;
use Cbox\AuditChain\Exceptions\CannotAnchorCheckpoint;
use Cbox\AuditChain\Exceptions\CannotCheckpointEmptyChain;
use Cbox\AuditChain\Exceptions\CheckpointSigningUnavailable;
use Cbox\AuditChain\Signing\Ed25519CheckpointSigner;
use Cbox\AuditChain\Tests\TestCase;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;
use Illuminate\Support\Facades\DB;

function checkpointKey(): ChainKey
{
    return ChainKey::of('tenant_a', 'ledger');
}

/**
 * @param  list<string>  $actions
 */
function recordAll(array $actions, ?ChainKey $key = null): void
{
    foreach ($actions as $action) {
        app(AuditChain::class)->record($key ?? checkpointKey(), ChainEvent::system($action));
    }
}

it('signs the chain head with the configured Ed25519 key', function (): void {
    recordAll(['a', 'b']);
    $head = DB::table('audit_chain_entries')->where('sequence', 2)->value('hash');

    $checkpoint = app(AuditChain::class)->checkpoint(checkpointKey());

    expect($checkpoint->exists)->toBeTrue()
        ->and($checkpoint->up_to_sequence)->toBe(2)
        ->and($checkpoint->root_hash)->toBe($head)
        ->and($checkpoint->chainPartition())->toBe('tenant_a')
        ->and($checkpoint->scope)->toBe('ledger')
        ->and($checkpoint->signature)->toStartWith('acp1.'.TestCase::TEST_KEY_ID.'.');

    $claims = app(CheckpointSigner::class)->verify($checkpoint->signature);

    expect($claims->partition)->toBe('tenant_a')
        ->and($claims->scope)->toBe('ledger')
        ->and($claims->upToSequence)->toBe(2)
        ->and($claims->rootHash)->toBe($head);
});

it('refuses to checkpoint a chain with no entries', function (): void {
    expect(fn () => app(AuditChain::class)->checkpoint(checkpointKey()))
        ->toThrow(CannotCheckpointEmptyChain::class, 'tenant_a/ledger');
});

it('refuses to sign without a key rather than signing with nothing', function (): void {
    config(['audit-chain.signing.secret_key' => null]);
    app()->forgetInstance(CheckpointSigner::class);
    app()->forgetInstance(AuditChain::class);

    recordAll(['a']);

    expect(fn () => app(AuditChain::class)->checkpoint(checkpointKey()))
        ->toThrow(CheckpointSigningUnavailable::class);

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(0);
});

it('still verifies a checkpointed chain (no false positive)', function (): void {
    recordAll(['a', 'b']);
    app(AuditChain::class)->checkpoint(checkpointKey());
    recordAll(['c']);

    expect(app(AuditChain::class)->verify(checkpointKey())->valid)->toBeTrue();
});

it('detects truncation below the newest checkpoint', function (): void {
    recordAll(['a', 'b', 'c']);
    app(AuditChain::class)->checkpoint(checkpointKey());

    DB::table('audit_chain_entries')->where('sequence', 3)->delete();

    $result = app(AuditChain::class)->verify(checkpointKey());

    expect($result->valid)->toBeFalse()
        ->and($result->brokenAtSequence)->toBe(3)
        ->and($result->break)->toBe(ChainBreak::CheckpointAnchorMissing);
});

it('detects a wiped chain once it has been checkpointed', function (): void {
    recordAll(['a']);
    app(AuditChain::class)->checkpoint(checkpointKey());

    DB::table('audit_chain_entries')->delete();

    expect(app(AuditChain::class)->verify(checkpointKey())->break)->toBe(ChainBreak::CheckpointAnchorMissing);
});

/**
 * An attacker with database write access truncates the tail AND rewrites the stored
 * checkpoint to describe the shorter chain. The anchor then matches; only the signature
 * disagrees — which is exactly what must be checked.
 */
it('refuses a checkpoint row rewritten to match a truncated chain', function (): void {
    recordAll(['a', 'b', 'c']);
    $second = DB::table('audit_chain_entries')->where('sequence', 2)->value('hash');
    app(AuditChain::class)->checkpoint(checkpointKey());

    DB::table('audit_chain_entries')->where('sequence', 3)->delete();
    DB::table('audit_chain_checkpoints')->update(['root_hash' => $second, 'up_to_sequence' => 2]);

    $result = app(AuditChain::class)->verify(checkpointKey());

    expect($result->valid)->toBeFalse()
        ->and($result->break)->toBe(ChainBreak::CheckpointPayloadMismatch);
});

it('refuses a checkpoint whose signature was replaced', function (): void {
    recordAll(['a']);
    app(AuditChain::class)->checkpoint(checkpointKey());

    DB::table('audit_chain_checkpoints')->update(['signature' => 'not.a.token']);

    expect(app(AuditChain::class)->verify(checkpointKey())->break)->toBe(ChainBreak::CheckpointSignatureInvalid);
});

it('refuses a genuine checkpoint of ANOTHER chain copied onto this one', function (): void {
    $other = ChainKey::of('tenant_b', 'ledger');

    // Same scope and same content in two partitions: identical sequences, so only the
    // partition bound into the signature tells the two checkpoints apart.
    recordAll(['a'], checkpointKey());
    recordAll(['a'], $other);

    $foreign = app(AuditChain::class)->checkpoint($other);
    app(AuditChain::class)->checkpoint(checkpointKey());

    DB::table('audit_chain_checkpoints')->where('partition_key', 'tenant_a')->update([
        'signature' => $foreign->signature,
        'root_hash' => $foreign->root_hash,
    ]);

    expect(app(AuditChain::class)->verify(checkpointKey())->break)->toBe(ChainBreak::CheckpointPayloadMismatch);
});

it('hands every signed checkpoint to the anchor, inside the same transaction', function (): void {
    $anchored = new ArrayObject;

    app()->instance(CheckpointAnchor::class, new class($anchored) implements CheckpointAnchor
    {
        /** @param ArrayObject<int, SignedCheckpoint> $anchored */
        public function __construct(private ArrayObject $anchored) {}

        public function anchor(SignedCheckpoint $checkpoint): void
        {
            $this->anchored[] = $checkpoint;
        }
    });
    app()->forgetInstance(AuditChain::class);

    recordAll(['a', 'b']);
    $checkpoint = app(AuditChain::class)->checkpoint(checkpointKey());

    expect($anchored)->toHaveCount(1)
        ->and($anchored[0]->checkpointId)->toBe($checkpoint->id)
        ->and($anchored[0]->token)->toBe($checkpoint->signature)
        ->and($anchored[0]->claims->upToSequence)->toBe(2)
        ->and($anchored[0]->key->equals(checkpointKey()))->toBeTrue();
});

it('rolls the checkpoint row back when the anchor fails, so the next pass retries', function (): void {
    app()->instance(CheckpointAnchor::class, new class implements CheckpointAnchor
    {
        public function anchor(SignedCheckpoint $checkpoint): void
        {
            throw CannotAnchorCheckpoint::because('bucket unreachable');
        }
    });
    app()->forgetInstance(AuditChain::class);

    recordAll(['a']);

    expect(fn () => app(AuditChain::class)->checkpoint(checkpointKey()))->toThrow(CannotAnchorCheckpoint::class);

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(0);
});

it('keeps verifying checkpoints signed by a retired key', function (): void {
    recordAll(['a']);
    app(AuditChain::class)->checkpoint(checkpointKey());

    // Rotate: a new active key, the old PUBLIC key kept in the keyring.
    $old = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair((string) base64_decode(TestCase::TEST_SEED_BASE64)));

    config([
        'audit-chain.signing.key_id' => 'next-key',
        'audit-chain.signing.secret_key' => Ed25519CheckpointSigner::generateKeyPair()['secret_key'],
        'audit-chain.signing.public_keys' => [TestCase::TEST_KEY_ID => base64_encode($old)],
    ]);
    app()->forgetInstance(CheckpointSigner::class);
    app()->forgetInstance(AuditChain::class);

    expect(app(AuditChain::class)->verify(checkpointKey())->valid)->toBeTrue();

    // ...and dropping the retired key is exactly what breaks it.
    config(['audit-chain.signing.public_keys' => []]);
    app()->forgetInstance(CheckpointSigner::class);
    app()->forgetInstance(AuditChain::class);

    expect(app(AuditChain::class)->verify(checkpointKey())->break)->toBe(ChainBreak::CheckpointSignatureInvalid);
});
