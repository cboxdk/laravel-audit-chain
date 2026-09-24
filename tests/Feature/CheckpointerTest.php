<?php

declare(strict_types=1);

use Cbox\AuditChain\AuditChainServiceProvider;
use Cbox\AuditChain\Checkpointer;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainFilter;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Cbox\AuditChain\ValueObjects\ChainVerification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function append(string $partition, string $scope, string $action = 'x'): void
{
    app(AuditChain::class)->record(ChainKey::of($partition, $scope), ChainEvent::system($action));
}

it('signs every chain that has entries, each at its own head', function (): void {
    append('tenant_a', 'ledger');
    append('tenant_a', 'ledger');
    append('tenant_a', 'users');
    append('tenant_b', 'ledger');

    $outcomes = app(Checkpointer::class)->checkpointAll();

    expect(array_map(static fn ($o): string => $o->key->describe().'@'.$o->headSequence, $outcomes))
        ->toBe(['tenant_a/ledger@2', 'tenant_a/users@1', 'tenant_b/ledger@1'])
        ->and(array_filter($outcomes, static fn ($o): bool => ! $o->wasSigned()))->toBe([])
        ->and(DB::table('audit_chain_checkpoints')->count())->toBe(3);

    // The point of the exercise: truncating a checkpointed chain is now detectable.
    DB::table('audit_chain_entries')->where('partition_key', 'tenant_a')->where('scope', 'ledger')->where('sequence', 2)->delete();

    expect(app(AuditChain::class)->verify(ChainKey::of('tenant_a', 'ledger'))->valid)->toBeFalse();
});

it('is idempotent — an unchanged chain is skipped, an advanced one is signed again', function (): void {
    append('tenant_a', 'ledger');

    app(Checkpointer::class)->checkpointAll();
    $second = app(Checkpointer::class)->checkpointAll();

    expect($second)->toHaveCount(1)
        ->and($second[0]->wasSkipped())->toBeTrue()
        ->and($second[0]->skippedReason)->toBe('already checkpointed at head')
        ->and(DB::table('audit_chain_checkpoints')->count())->toBe(1);

    append('tenant_a', 'ledger');
    $third = app(Checkpointer::class)->checkpointAll();

    expect($third[0]->wasSigned())->toBeTrue()
        ->and($third[0]->headSequence)->toBe(2)
        ->and($third[0]->checkpointedSequence)->toBe(1)
        ->and(DB::table('audit_chain_checkpoints')->count())->toBe(2);
});

it('re-signs an unchanged head when forced', function (): void {
    append('tenant_a', 'ledger');

    app(Checkpointer::class)->checkpointAll();
    $forced = app(Checkpointer::class)->checkpointAll(force: true);

    expect($forced[0]->wasSigned())->toBeTrue()
        ->and(DB::table('audit_chain_checkpoints')->count())->toBe(2);
});

it('signs nothing on a dry run', function (): void {
    append('tenant_a', 'ledger');

    $outcomes = app(Checkpointer::class)->checkpointAll(dryRun: true);

    expect($outcomes[0]->wasSkipped())->toBeFalse()
        ->and($outcomes[0]->wasSigned())->toBeFalse()
        ->and($outcomes[0]->headSequence)->toBe(1)
        ->and(DB::table('audit_chain_checkpoints')->count())->toBe(0);
});

it('narrows to the named partitions and scopes', function (): void {
    append('tenant_a', 'ledger');
    append('tenant_a', 'users');
    append('tenant_b', 'ledger');

    $outcomes = app(Checkpointer::class)->checkpointAll(filter: new ChainFilter(partitions: ['tenant_a'], scopes: ['users']));

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0]->key->describe())->toBe('tenant_a/users');

    expect(app(Checkpointer::class)->checkpointAll(filter: new ChainFilter(partitions: ['absent'])))->toBe([]);
});

it('signs each chain from inside its own ChainContext', function (): void {
    append('tenant_a', 'ledger');
    append('tenant_b', 'ledger');

    $seen = new ArrayObject;

    app()->instance(ChainContext::class, new class($seen) implements ChainContext
    {
        /** @param ArrayObject<int, string> $seen */
        public function __construct(private ArrayObject $seen) {}

        public function run(ChainKey $key, Closure $callback): mixed
        {
            $this->seen[] = 'enter '.$key->describe();

            try {
                return $callback();
            } finally {
                $this->seen[] = 'leave '.$key->describe();
            }
        }
    });
    app()->forgetInstance(Checkpointer::class);

    app(Checkpointer::class)->checkpointAll();

    expect($seen->getArrayCopy())->toBe([
        'enter tenant_a/ledger', 'leave tenant_a/ledger',
        'enter tenant_b/ledger', 'leave tenant_b/ledger',
    ]);
});

it('records a chain it could not sign and carries on with the rest', function (): void {
    append('tenant_a', 'ledger');
    append('tenant_b', 'ledger');

    $inner = app(AuditChain::class);

    app()->instance(AuditChain::class, new class($inner) implements AuditChain
    {
        public function __construct(private readonly AuditChain $inner) {}

        public function record(ChainKey $key, ChainEvent $event): ChainEntry
        {
            return $this->inner->record($key, $event);
        }

        public function verify(ChainKey $key, int $fromSequence = 1, ?int $toSequence = null): ChainVerification
        {
            return $this->inner->verify($key, $fromSequence, $toSequence);
        }

        public function head(ChainKey $key): int
        {
            return $this->inner->head($key);
        }

        public function checkpoint(ChainKey $key): ChainCheckpoint
        {
            if ($key->partition === 'tenant_a') {
                throw new RuntimeException('no signing key for tenant_a');
            }

            return $this->inner->checkpoint($key);
        }
    });
    app()->forgetInstance(Checkpointer::class);

    $outcomes = app(Checkpointer::class)->checkpointAll();

    expect($outcomes[0]->hasFailed())->toBeTrue()
        ->and($outcomes[0]->failureReason)->toBe('RuntimeException: no signing key for tenant_a')
        ->and($outcomes[1]->wasSigned())->toBeTrue()
        ->and(DB::table('audit_chain_checkpoints')->pluck('partition_key')->all())->toBe(['tenant_b']);

    $this->artisan('audit-chain:checkpoint')
        ->expectsOutputToContain('1 chain(s) could not be checkpointed.')
        ->assertFailed();
});

it('signs from the command and says what it did', function (): void {
    append('tenant_a', 'ledger');

    $this->artisan('audit-chain:checkpoint --dry-run')
        ->expectsOutputToContain('Dry run — nothing will be signed.')
        ->expectsOutputToContain('Would sign 1 checkpoint(s) over 1 chain(s).')
        ->assertSuccessful();

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(0);

    $this->artisan('audit-chain:checkpoint --partition=tenant_a --scope=ledger')
        ->expectsOutputToContain('Signed 1 checkpoint(s) over 1 chain(s).')
        ->expectsOutputToContain('A signed checkpoint is permanent evidence.')
        ->assertSuccessful();

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(1);

    $this->artisan('audit-chain:checkpoint --force')->assertSuccessful();

    expect(DB::table('audit_chain_checkpoints')->count())->toBe(2);
});

it('reports honestly when there is nothing to checkpoint', function (): void {
    $this->artisan('audit-chain:checkpoint')
        ->expectsOutputToContain('No audit chains to checkpoint.')
        ->assertSuccessful();
});

/**
 * The one-way door: the first signature forecloses any later re-chain, so the pass
 * ships OFF and an operator turns it on deliberately.
 */
it('is not scheduled by default, and is scheduled when the operator opts in', function (): void {
    $scheduled = static fn (string $name): array => array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => $event->description === $name,
    ));

    expect(config('audit-chain.checkpoint.schedule'))->toBeFalse()
        ->and(config('audit-chain.verify.schedule'))->toBeFalse()
        ->and($scheduled('audit-chain:checkpoint'))->toBe([])
        ->and($scheduled('audit-chain:verify'))->toBe([]);

    config([
        'audit-chain.checkpoint.schedule' => true,
        'audit-chain.verify.schedule' => true,
        'audit-chain.verify.time' => 'not a time',
    ]);

    app()->register(AuditChainServiceProvider::class, force: true);

    expect($scheduled('audit-chain:checkpoint'))->toHaveCount(1)
        ->and($scheduled('audit-chain:checkpoint')[0]->expression)->toBe('40 2 * * *')
        // A malformed time falls back rather than silently unscheduling the pass.
        ->and($scheduled('audit-chain:verify'))->toHaveCount(1)
        ->and($scheduled('audit-chain:verify')[0]->expression)->toBe('10 3 * * *')
        ->and($scheduled('audit-chain:verify')[0]->command)->toContain('--window=1000');
});
