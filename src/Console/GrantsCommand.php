<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Console;

use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Locking\AnchorRowChainLock;
use Cbox\AuditChain\Locking\AutoChainLock;
use Cbox\AuditChain\Locking\PostgresAdvisoryChainLock;
use Cbox\AuditChain\Security\DatabaseEngine;
use Cbox\AuditChain\Security\LeastPrivilegeGrants;
use Cbox\AuditChain\Storage\ChainModels;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `audit-chain:grants {role}` — print the SQL that gives a runtime role the least it
 * needs, and makes the chain tables append-only, for this app's connection, tables and
 * lock strategy.
 *
 * Prints, never runs: granting privileges is a deployment decision made by an account
 * this application should not be running as.
 */
class GrantsCommand extends Command
{
    protected $signature = 'audit-chain:grants
        {role : The database role (PostgreSQL) or user (MySQL, MariaDB) the app appends as}
        {--host=% : MySQL/MariaDB only: the account host}
        {--lock= : anchor or advisory (default: the configured lock strategy)}';

    protected $description = 'Print least-privilege grants and append-only triggers for the audit-chain tables.';

    public function handle(ChainModels $models, ChainLock $bound): int
    {
        $entry = $models->newEntry();
        $connection = $entry->getConnection();
        $engine = DatabaseEngine::of($connection);

        if ($engine === null) {
            $this->error("The [{$connection->getDriverName()}] connection has no database roles to grant to. Supported: PostgreSQL, MySQL, MariaDB.");

            return self::FAILURE;
        }

        $option = $this->option('lock');
        $lock = is_string($option) && $option !== '' ? $option : $this->lockOf($bound, $engine);
        $host = $this->option('host');
        $role = $this->argument('role');

        try {
            $grants = new LeastPrivilegeGrants(
                engine: $engine,
                role: is_string($role) ? $role : '',
                lock: $lock,
                entriesTable: $connection->getTablePrefix().$entry->getTable(),
                checkpointsTable: $connection->getTablePrefix().$models->newCheckpoint()->getTable(),
                database: $engine === DatabaseEngine::Postgres ? null : $connection->getDatabaseName(),
                host: is_string($host) && $host !== '' ? $host : '%',
            );
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        $this->line("-- {$engine->name}, {$lock} lock. Run as the tables' owner.");
        $this->line($grants->needsRowLockGrant()
            ? '-- The anchor lock is SELECT ... FOR UPDATE, a write privilege on this engine: the role gets UPDATE on `hash` only, and the trigger refuses any actual UPDATE.'
            : '-- The role gets SELECT and INSERT and nothing else.');
        $this->line('-- The triggers refuse UPDATE and DELETE for EVERY role, the owner included. Drop them only for a deliberate re-chain.');
        $this->newLine();

        foreach ($grants->statements() as $statement) {
            $this->line($statement.';');
        }

        return self::SUCCESS;
    }

    private function lockOf(ChainLock $bound, DatabaseEngine $engine): string
    {
        return match (true) {
            $bound instanceof PostgresAdvisoryChainLock => LeastPrivilegeGrants::LOCK_ADVISORY,
            $bound instanceof AutoChainLock => $engine === DatabaseEngine::Postgres ? LeastPrivilegeGrants::LOCK_ADVISORY : LeastPrivilegeGrants::LOCK_ANCHOR,
            $bound instanceof AnchorRowChainLock => LeastPrivilegeGrants::LOCK_ANCHOR,
            // A custom strategy: assume the more demanding grant set, and say so.
            default => LeastPrivilegeGrants::LOCK_ANCHOR,
        };
    }
}
