<?php

declare(strict_types=1);

use Cbox\AuditChain\Security\DatabaseEngine;
use Cbox\AuditChain\Security\LeastPrivilegeGrants;

/*
 * The grant matrix, pinned. These are the statements `audit-chain:grants` prints and the
 * least-privilege tests execute on real engines (tests/Feature/LeastPrivilegeTest.php).
 */

it('grants PostgreSQL SELECT and INSERT only under the advisory lock', function (): void {
    $grants = new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app_rt', 'advisory');

    expect($grants->needsRowLockGrant())->toBeFalse()
        ->and($grants->grants())->toBe([
            'GRANT SELECT, INSERT ON "audit_chain_entries" TO "app_rt"',
            'GRANT SELECT, INSERT ON "audit_chain_checkpoints" TO "app_rt"',
        ]);
});

it('adds UPDATE on the hash column alone for the anchor lock on PostgreSQL and MySQL', function (DatabaseEngine $engine, string $expected): void {
    $grants = new LeastPrivilegeGrants($engine, 'app_rt', 'anchor', database: 'app');

    expect($grants->needsRowLockGrant())->toBeTrue()
        ->and($grants->grants()[2])->toBe($expected)
        ->and($grants->grants())->toHaveCount(3);
})->with([
    'postgres' => [DatabaseEngine::Postgres, 'GRANT UPDATE ("hash") ON "audit_chain_entries" TO "app_rt"'],
    'mysql' => [DatabaseEngine::MySql, "GRANT UPDATE (`hash`) ON `app`.`audit_chain_entries` TO 'app_rt'@'%'"],
]);

it('needs no row-lock grant on MariaDB', function (): void {
    $grants = new LeastPrivilegeGrants(DatabaseEngine::MariaDb, 'app_rt', 'anchor', database: 'app', host: '10.0.%');

    expect($grants->needsRowLockGrant())->toBeFalse()
        ->and($grants->grants())->toBe([
            "GRANT SELECT, INSERT ON `app`.`audit_chain_entries` TO 'app_rt'@'10.0.%'",
            "GRANT SELECT, INSERT ON `app`.`audit_chain_checkpoints` TO 'app_rt'@'10.0.%'",
        ]);
});

it('makes both tables append-only on PostgreSQL, TRUNCATE included', function (): void {
    $statements = (new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app_rt', 'advisory'))->appendOnly();

    expect($statements[0])->toStartWith('CREATE OR REPLACE FUNCTION "audit_chain_refuse_change"() RETURNS trigger')
        ->and($statements[0])->toContain("USING ERRCODE = 'insufficient_privilege'")
        ->and($statements)->toContain(
            'CREATE TRIGGER "audit_chain_entries_append_only" BEFORE UPDATE OR DELETE ON "audit_chain_entries" FOR EACH ROW EXECUTE FUNCTION "audit_chain_refuse_change"()',
            'CREATE TRIGGER "audit_chain_entries_no_truncate" BEFORE TRUNCATE ON "audit_chain_entries" FOR EACH STATEMENT EXECUTE FUNCTION "audit_chain_refuse_change"()',
            'CREATE TRIGGER "audit_chain_checkpoints_append_only" BEFORE UPDATE OR DELETE ON "audit_chain_checkpoints" FOR EACH ROW EXECUTE FUNCTION "audit_chain_refuse_change"()',
        );
});

it('makes both tables append-only on MySQL and MariaDB', function (): void {
    $statements = (new LeastPrivilegeGrants(DatabaseEngine::MySql, 'app_rt', 'anchor', database: 'app'))->appendOnly();

    expect($statements)->toContain(
        "CREATE TRIGGER `app`.`audit_chain_entries_no_update` BEFORE UPDATE ON `app`.`audit_chain_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit chain rows are append-only'",
        "CREATE TRIGGER `app`.`audit_chain_checkpoints_no_delete` BEFORE DELETE ON `app`.`audit_chain_checkpoints` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit chain rows are append-only'",
    );
});

it('can undo the triggers for a deliberate re-chain', function (): void {
    expect((new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app_rt', 'advisory'))->dropAppendOnly())->toContain(
        'DROP TRIGGER IF EXISTS "audit_chain_entries_append_only" ON "audit_chain_entries"',
        'DROP FUNCTION IF EXISTS "audit_chain_refuse_change"()',
    );
});

it('uses configured table names', function (): void {
    $grants = new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app_rt', 'advisory', 'trail_entries', 'trail_checkpoints');

    expect($grants->grants()[0])->toBe('GRANT SELECT, INSERT ON "trail_entries" TO "app_rt"');
});

it('refuses anything it would have to quote its way around', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'role with a quote' => [fn () => new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app"; DROP', 'advisory')],
    'table with a space' => [fn () => new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app', 'advisory', 'bad table')],
    'host with a quote' => [fn () => new LeastPrivilegeGrants(DatabaseEngine::MySql, 'app', 'anchor', host: "%'--")],
    'unknown lock' => [fn () => new LeastPrivilegeGrants(DatabaseEngine::Postgres, 'app', 'optimistic')],
    'advisory off PostgreSQL' => [fn () => new LeastPrivilegeGrants(DatabaseEngine::MySql, 'app', 'advisory')],
]);
