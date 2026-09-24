<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Models;

/**
 * Table, connection and partition column for the package's own models, read from
 * `audit-chain.storage.*` on every call so a config change (or a test) takes effect
 * without a new process.
 *
 * A host model over its own table does not use this: it states its `$table` and
 * connection the ordinary Eloquent way and is unaffected by this package's config.
 */
trait ReadsChainStorageConfig
{
    /**
     * An explicit connection wins — one set on a subclass, or the one Eloquent stamps on
     * a model it hydrated from a query — then the configured one, then the default.
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return parent::getConnectionName();
        }

        $connection = config('audit-chain.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function chainPartitionColumn(): string
    {
        $column = config('audit-chain.storage.partition_column');

        return is_string($column) && $column !== '' ? $column : 'partition_key';
    }

    /**
     * A `$table` stated on a subclass wins over the configured name.
     */
    protected function configuredTable(string $key, string $default): string
    {
        if (is_string($this->table) && $this->table !== '') {
            return $this->table;
        }

        $table = config('audit-chain.storage.tables.'.$key);

        return is_string($table) && $table !== '' ? $table : $default;
    }
}
