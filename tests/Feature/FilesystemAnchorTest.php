<?php

declare(strict_types=1);

use Cbox\AuditChain\Anchoring\FilesystemCheckpointAnchor;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Exceptions\CannotAnchorCheckpoint;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('anchors');

    config(['audit-chain.anchor.driver' => 'filesystem', 'audit-chain.anchor.disk' => 'anchors']);
    app()->forgetInstance(CheckpointAnchor::class);
    app()->forgetInstance(AuditChain::class);
});

function anchoredCheckpoint(string $partition = 'tenant_a', string $scope = 'ledger', string $token = 'acp1.k.p.s'): SignedCheckpoint
{
    return new SignedCheckpoint(
        ChainKey::of($partition, $scope),
        new CheckpointClaims($scope, 7, str_repeat('ab', 32), 1788256800, $partition),
        $token,
        '01J8ZK3V9Q4XW5T6Y7R8S9A0BC',
    );
}

it('is what the config selects', function (): void {
    expect(app(CheckpointAnchor::class))->toBeInstanceOf(FilesystemCheckpointAnchor::class);
});

it('exports every signed checkpoint as a self-verifying document', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    app(AuditChain::class)->record($key, ChainEvent::system('a'));
    $checkpoint = app(AuditChain::class)->checkpoint($key);

    $files = Storage::disk('anchors')->allFiles();

    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith('audit-chain/checkpoints/tenant_a/ledger/00000000000000000001-');

    $document = json_decode((string) Storage::disk('anchors')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($document['format'])->toBe('audit-chain.anchor/v1')
        ->and($document['checkpoint_id'])->toBe($checkpoint->id)
        ->and($document['root_hash'])->toBe($checkpoint->root_hash)
        ->and($document['token'])->toBe($checkpoint->signature);

    // The exported token alone re-verifies — no database needed.
    $claims = app(CheckpointSigner::class)->verify($document['token']);

    expect($claims->rootHash)->toBe($checkpoint->root_hash)
        ->and($claims->upToSequence)->toBe(1);
});

it('encodes path segments so no key can climb out of its directory', function (): void {
    $anchor = new FilesystemCheckpointAnchor(Storage::disk('anchors'), '/audit/');

    expect($anchor->pathFor(anchoredCheckpoint('../../etc', 'a/b.c')))
        ->toStartWith('audit/%2E%2E%2F%2E%2E%2Fetc/a%2Fb%2Ec/00000000000000000007-');
});

it('is idempotent for the same checkpoint and refuses to overwrite a different one', function (): void {
    $anchor = new FilesystemCheckpointAnchor(Storage::disk('anchors'));
    $checkpoint = anchoredCheckpoint();

    $anchor->anchor($checkpoint);
    $anchor->anchor($checkpoint);

    expect(Storage::disk('anchors')->allFiles())->toHaveCount(1);

    $path = $anchor->pathFor($checkpoint);
    Storage::disk('anchors')->put($path, 'something else');

    expect(fn () => $anchor->anchor($checkpoint))->toThrow(CannotAnchorCheckpoint::class, 'Refusing to overwrite');
});

it('rolls the checkpoint back when the disk fails, so the next pass tries again', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');
    app(AuditChain::class)->record($key, ChainEvent::system('a'));

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('put')->andThrow(new RuntimeException('403 Forbidden'));

    app()->instance(CheckpointAnchor::class, new FilesystemCheckpointAnchor($disk));
    app()->forgetInstance(AuditChain::class);

    expect(fn () => app(AuditChain::class)->checkpoint($key))
        ->toThrow(CannotAnchorCheckpoint::class, '403 Forbidden');

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(0);
});

it('refuses an unknown driver by name', function (): void {
    config(['audit-chain.anchor.driver' => 'carrier-pigeon']);
    app()->forgetInstance(CheckpointAnchor::class);

    expect(fn () => app(CheckpointAnchor::class))->toThrow(InvalidArgumentException::class, 'carrier-pigeon');
});
