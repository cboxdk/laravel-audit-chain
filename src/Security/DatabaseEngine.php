<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Security;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;

/**
 * The engines whose privilege model {@see LeastPrivilegeGrants} knows. They differ in
 * exactly the way that matters here: whether a row lock is a write privilege.
 */
enum DatabaseEngine: string
{
    case Postgres = 'pgsql';
    case MySql = 'mysql';
    case MariaDb = 'mariadb';

    /**
     * The engine behind a connection, or null for one without database roles (SQLite).
     * A `mysql`-driver connection to a MariaDB server is MariaDB: the server decides.
     */
    public static function of(Connection $connection): ?self
    {
        return match (true) {
            $connection instanceof MariaDbConnection => self::MariaDb,
            $connection instanceof MySqlConnection => $connection->isMaria() ? self::MariaDb : self::MySql,
            $connection->getDriverName() === 'pgsql' => self::Postgres,
            default => null,
        };
    }

    /**
     * Whether `SELECT … FOR UPDATE` (the anchor lock) needs more than SELECT.
     *
     * Measured, not assumed: PostgreSQL 16 refuses every row-lock mode without UPDATE
     * on at least one column (SQLSTATE 42501); MySQL 8.4 refuses it without UPDATE (one
     * column suffices), DELETE or LOCK TABLES (error 1142); MariaDB 11.8 allows it on
     * SELECT alone. tests/Feature/LeastPrivilegeTest.php holds each engine to this.
     */
    public function rowLockNeedsUpdate(): bool
    {
        return $this !== self::MariaDb;
    }
}
