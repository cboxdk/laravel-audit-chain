<?php

declare(strict_types=1);

use Cbox\AuditChain\ChainVerifier;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;

function appendMany(ChainKey $key, int $count): void
{
    foreach (range(1, $count) as $i) {
        app(AuditChain::class)->record($key, ChainEvent::system('e'.$i));
    }
}

it('verifies every chain and exits zero when all are intact', function (): void {
    appendMany(ChainKey::of('tenant_a', 'ledger'), 3);
    appendMany(ChainKey::of('tenant_b', 'ledger'), 2);

    $this->artisan('audit-chain:verify')
        ->expectsOutputToContain('All 2 chain(s) verified.')
        ->assertSuccessful();
});

it('exits non-zero and names the break when a chain is tampered with', function (): void {
    appendMany(ChainKey::of('tenant_a', 'ledger'), 3);
    appendMany(ChainKey::of('tenant_b', 'ledger'), 2);

    DB::table('audit_chain_entries')->where('partition_key', 'tenant_b')->where('sequence', 2)->update(['action' => 'forged']);

    $this->artisan('audit-chain:verify')
        ->expectsOutputToContain('BROKEN at 2 (content hash mismatch (tampered))')
        ->expectsOutputToContain('1 of 2 chain(s) failed verification.')
        ->assertFailed();
});

it('verifies only the newest entries with --window, still linked to the one before', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');
    appendMany($key, 10);

    $results = app(ChainVerifier::class)->verifyAll(window: 3);

    expect($results)->toHaveCount(1)
        ->and($results[0]->isIntact())->toBeTrue()
        ->and($results[0]->verification?->verifiedCount)->toBe(3);

    // Tampering OUTSIDE the window is (by design) not re-hashed by a windowed pass...
    DB::table('audit_chain_entries')->where('sequence', 2)->update(['action' => 'forged']);

    expect(app(ChainVerifier::class)->verifyAll(window: 3)[0]->isIntact())->toBeTrue();

    // ...and a full pass catches it.
    $this->artisan('audit-chain:verify')->assertFailed();
    $this->artisan('audit-chain:verify --window=3')->assertSuccessful();
});

it('refuses a window that is not a positive integer', function (): void {
    $this->artisan('audit-chain:verify --window=0')
        ->expectsOutputToContain('--window must be a positive integer.')
        ->assertExitCode(2);
});

it('reports honestly when there is nothing to verify', function (): void {
    $this->artisan('audit-chain:verify')
        ->expectsOutputToContain('No audit chains to verify.')
        ->assertSuccessful();
});
