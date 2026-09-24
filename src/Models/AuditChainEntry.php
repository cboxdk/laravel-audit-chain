<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The package's default entry model, over the table its publishable migration
 * creates (`audit_chain_entries` unless configured otherwise).
 *
 * Subclass it and set `audit-chain.models.entry` to add relations or accessors; the
 * chain columns and casts stay the package's.
 */
class AuditChainEntry extends ChainEntry
{
    use HasUlids;
    use ReadsChainStorageConfig;

    public function getTable(): string
    {
        return $this->configuredTable('entries', 'audit_chain_entries');
    }
}
