<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\ChainLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('says so when the connection has no roles to grant to', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite only');
    }

    $this->artisan('audit-chain:grants', ['role' => 'app_rt'])
        ->expectsOutputToContain('has no database roles to grant to')
        ->assertFailed();
});

it('prints the grants for the configured lock strategy', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only');
    }

    config(['audit-chain.lock.driver' => 'auto']);
    app()->forgetInstance(ChainLock::class);

    Artisan::call('audit-chain:grants', ['role' => 'app_rt']);
    $advisory = Artisan::output();

    expect($advisory)->toContain('-- Postgres, advisory lock.')
        ->and($advisory)->toContain('GRANT SELECT, INSERT ON "audit_chain_entries" TO "app_rt";')
        ->and($advisory)->not->toContain('GRANT UPDATE');

    Artisan::call('audit-chain:grants', ['role' => 'app_rt', '--lock' => 'anchor']);

    expect(Artisan::output())->toContain('GRANT UPDATE ("hash") ON "audit_chain_entries" TO "app_rt";');
});

it('refuses a role name it would have to quote around', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('needs an engine with roles');
    }

    $this->artisan('audit-chain:grants', ['role' => 'app"; drop table x; --'])->assertExitCode(2);
});
