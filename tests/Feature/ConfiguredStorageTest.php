<?php

declare(strict_types=1);

use Cbox\AuditChain\Checkpointer;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\Exceptions\InvalidChainModel;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;

/*
 * The package's own tables follow `audit-chain.storage.*` — connection, table names and
 * the partition column — from the migration through every read and write.
 *
 * Runs on a separate SQLite connection whatever the suite's engine: the migration is DDL,
 * and on MySQL DDL would commit the transaction the rest of the test runs in.
 */

beforeEach(function (): void {
    config([
        'database.connections.audit_side' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'audit-chain.storage.connection' => 'audit_side',
        'audit-chain.storage.tables.entries' => 'trail_entries',
        'audit-chain.storage.tables.checkpoints' => 'trail_checkpoints',
        'audit-chain.storage.partition_column' => 'tenant_id',
    ]);

    foreach ([ChainModels::class, AuditChain::class, ChainInventory::class, Checkpointer::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $this->artisan('migrate', [
        '--database' => 'audit_side',
        '--path' => dirname(__DIR__, 2).'/database/migrations',
        '--realpath' => true,
    ])->assertSuccessful();
});

it('creates, writes, reads and checkpoints through the configured storage', function (): void {
    $key = ChainKey::of('tenant_a', 'ledger');

    app(AuditChain::class)->record($key, ChainEvent::system('a'));
    app(AuditChain::class)->record($key, ChainEvent::system('b'));

    $side = DB::connection('audit_side');

    expect($side->table('trail_entries')->where('tenant_id', 'tenant_a')->count())->toBe(2)
        ->and(app(AuditChain::class)->verify($key)->valid)->toBeTrue()
        ->and(app(Checkpointer::class)->checkpointAll()[0]->wasSigned())->toBeTrue()
        ->and($side->table('trail_checkpoints')->where('tenant_id', 'tenant_a')->count())->toBe(1);

    // Nothing leaked onto the default connection's default tables.
    expect(DB::table('audit_chain_entries')->count())->toBe(0);
});

it('refuses a configured model that is not a chain model', function (): void {
    config(['audit-chain.models.entry' => stdClass::class]);
    app()->forgetInstance(ChainModels::class);

    expect(fn () => app(ChainModels::class))->toThrow(InvalidChainModel::class, 'must extend');
});
