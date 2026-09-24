<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\Artisan;

it('prints a key pair that signs and verifies checkpoints', function (): void {
    Artisan::call('audit-chain:keygen', ['--kid' => 'prod-2026']);
    $output = Artisan::output();

    expect($output)->toContain('AUDIT_CHAIN_SIGNING_KEY_ID=prod-2026');

    preg_match('/AUDIT_CHAIN_SIGNING_SECRET_KEY=(\S+)/', $output, $secret);

    expect($secret)->toHaveCount(2);

    config(['audit-chain.signing.key_id' => 'prod-2026', 'audit-chain.signing.secret_key' => $secret[1]]);
    app()->forgetInstance(CheckpointSigner::class);
    app()->forgetInstance(AuditChain::class);

    $key = ChainKey::of('p', 's');
    app(AuditChain::class)->record($key, ChainEvent::system('x'));
    $checkpoint = app(AuditChain::class)->checkpoint($key);

    expect($checkpoint->signature)->toStartWith('acp1.prod-2026.')
        ->and(app(AuditChain::class)->verify($key)->valid)->toBeTrue();
});

it('refuses a key id outside the token alphabet', function (): void {
    $this->artisan('audit-chain:keygen', ['--kid' => 'has.dots'])->assertExitCode(2);
});
