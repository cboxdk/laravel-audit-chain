<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Security;

use Cbox\AuditChain\Console\GrantsCommand;
use InvalidArgumentException;

/**
 * The exact SQL that gives a runtime database role the least it needs to append to,
 * verify and checkpoint the chains — and makes the tables append-only for every role.
 *
 * A runtime role needs SELECT and INSERT on both tables, nothing else, with one
 * exception: the anchor lock (`SELECT … FOR UPDATE`) is a write privilege on PostgreSQL
 * and MySQL 8, so under that strategy the role also gets UPDATE on the single `hash`
 * column. That grant is made unusable for an actual UPDATE by the append-only trigger,
 * which refuses UPDATE and DELETE (and TRUNCATE, on PostgreSQL) for EVERY role, the
 * table owner included. On PostgreSQL the advisory lock removes the need entirely.
 *
 * The statements are what {@see GrantsCommand} prints and what the least-privilege
 * tests execute against real engines, so what is documented is what is tested.
 *
 * Nothing here runs anything. Apply the statements as the tables' owner (PostgreSQL)
 * or an account that may create triggers and grant (MySQL, MariaDB).
 */
readonly class LeastPrivilegeGrants
{
    public const LOCK_ANCHOR = 'anchor';

    public const LOCK_ADVISORY = 'advisory';

    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * @param  string  $lock  `anchor` or `advisory` — the strategy the role will append under
     * @param  string|null  $database  MySQL/MariaDB only: the schema the tables live in
     * @param  string  $host  MySQL/MariaDB only: the account's host part
     */
    public function __construct(
        public DatabaseEngine $engine,
        public string $role,
        public string $lock,
        public string $entriesTable = 'audit_chain_entries',
        public string $checkpointsTable = 'audit_chain_checkpoints',
        public ?string $database = null,
        public string $host = '%',
    ) {
        foreach (['role' => $role, 'entries table' => $entriesTable, 'checkpoints table' => $checkpointsTable] as $what => $name) {
            if (preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException("The {$what} [{$name}] must match ".self::IDENTIFIER.'.');
            }
        }

        if ($database !== null && preg_match(self::IDENTIFIER, $database) !== 1) {
            throw new InvalidArgumentException("The database [{$database}] must match ".self::IDENTIFIER.'.');
        }

        if (preg_match('/\A[A-Za-z0-9_.%:-]{1,255}\z/', $host) !== 1) {
            throw new InvalidArgumentException("The host [{$host}] is not a valid account host.");
        }

        if (! in_array($lock, [self::LOCK_ANCHOR, self::LOCK_ADVISORY], true)) {
            throw new InvalidArgumentException("Unknown lock strategy [{$lock}]: anchor or advisory.");
        }

        if ($lock === self::LOCK_ADVISORY && $engine !== DatabaseEngine::Postgres) {
            throw new InvalidArgumentException('The advisory lock is PostgreSQL-only.');
        }
    }

    /**
     * Whether the role needs UPDATE on one column for its row lock.
     */
    public function needsRowLockGrant(): bool
    {
        return $this->lock === self::LOCK_ANCHOR && $this->engine->rowLockNeedsUpdate();
    }

    /**
     * The GRANT statements for the runtime role.
     *
     * @return list<string>
     */
    public function grants(): array
    {
        $to = $this->grantee();
        $statements = [];

        foreach ([$this->entriesTable, $this->checkpointsTable] as $table) {
            $statements[] = "GRANT SELECT, INSERT ON {$this->table($table)} TO {$to}";
        }

        if ($this->needsRowLockGrant()) {
            $statements[] = "GRANT UPDATE ({$this->quote('hash')}) ON {$this->table($this->entriesTable)} TO {$to}";
        }

        return $statements;
    }

    /**
     * The statements that make both tables append-only for every role. Idempotent.
     *
     * @return list<string>
     */
    public function appendOnly(): array
    {
        return $this->engine === DatabaseEngine::Postgres ? $this->postgresAppendOnly() : $this->mysqlAppendOnly();
    }

    /**
     * The statements that remove what {@see appendOnly()} added — for a deliberate
     * re-chain, which is the one legitimate reason to rewrite an entry.
     *
     * @return list<string>
     */
    public function dropAppendOnly(): array
    {
        $statements = [];

        foreach ([$this->entriesTable, $this->checkpointsTable] as $table) {
            foreach ($this->triggerNames($table) as $trigger) {
                $statements[] = $this->engine === DatabaseEngine::Postgres
                    ? "DROP TRIGGER IF EXISTS {$this->quote($trigger)} ON {$this->table($table)}"
                    : "DROP TRIGGER IF EXISTS {$this->qualified($trigger)}";
            }
        }

        if ($this->engine === DatabaseEngine::Postgres) {
            $statements[] = 'DROP FUNCTION IF EXISTS '.$this->quote('audit_chain_refuse_change').'()';
        }

        return $statements;
    }

    /**
     * Everything to run, in order: the append-only triggers, then the grants.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return [...$this->appendOnly(), ...$this->grants()];
    }

    /**
     * @return list<string>
     */
    private function postgresAppendOnly(): array
    {
        $function = $this->quote('audit_chain_refuse_change');

        $statements = [
            "CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$audit_chain\$\n"
            ."BEGIN\n"
            ."    RAISE EXCEPTION 'audit chain rows are append-only: % on % refused', TG_OP, TG_TABLE_NAME\n"
            ."        USING ERRCODE = 'insufficient_privilege';\n"
            ."END\n"
            .'$audit_chain$',
        ];

        foreach ([$this->entriesTable, $this->checkpointsTable] as $table) {
            [$rows, $truncate] = $this->triggerNames($table);

            $statements[] = "DROP TRIGGER IF EXISTS {$this->quote($rows)} ON {$this->table($table)}";
            $statements[] = "CREATE TRIGGER {$this->quote($rows)} BEFORE UPDATE OR DELETE ON {$this->table($table)} FOR EACH ROW EXECUTE FUNCTION {$function}()";
            $statements[] = "DROP TRIGGER IF EXISTS {$this->quote($truncate)} ON {$this->table($table)}";
            $statements[] = "CREATE TRIGGER {$this->quote($truncate)} BEFORE TRUNCATE ON {$this->table($table)} FOR EACH STATEMENT EXECUTE FUNCTION {$function}()";
        }

        return $statements;
    }

    /**
     * MySQL and MariaDB: one trigger per event. TRUNCATE fires no trigger there; it
     * needs the DROP privilege, which a runtime role never has.
     *
     * @return list<string>
     */
    private function mysqlAppendOnly(): array
    {
        $statements = [];

        foreach ([$this->entriesTable, $this->checkpointsTable] as $table) {
            foreach (array_combine(['UPDATE', 'DELETE'], $this->triggerNames($table)) as $event => $trigger) {
                $statements[] = "DROP TRIGGER IF EXISTS {$this->qualified($trigger)}";
                $statements[] = "CREATE TRIGGER {$this->qualified($trigger)} BEFORE {$event} ON {$this->table($table)} FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit chain rows are append-only'";
            }
        }

        return $statements;
    }

    /**
     * @return array{string, string}
     */
    private function triggerNames(string $table): array
    {
        return $this->engine === DatabaseEngine::Postgres
            ? [$table.'_append_only', $table.'_no_truncate']
            : [$table.'_no_update', $table.'_no_delete'];
    }

    private function grantee(): string
    {
        return $this->engine === DatabaseEngine::Postgres
            ? $this->quote($this->role)
            : "'{$this->role}'@'{$this->host}'";
    }

    private function table(string $table): string
    {
        return $this->qualified($table);
    }

    private function qualified(string $name): string
    {
        return $this->engine !== DatabaseEngine::Postgres && $this->database !== null
            ? $this->quote($this->database).'.'.$this->quote($name)
            : $this->quote($name);
    }

    private function quote(string $identifier): string
    {
        return $this->engine === DatabaseEngine::Postgres ? '"'.$identifier.'"' : '`'.$identifier.'`';
    }
}
