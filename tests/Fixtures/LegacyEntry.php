<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests\Fixtures;

use Cbox\AuditChain\Models\ChainEntry;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A host model over a chain another implementation wrote: its own table, its own
 * partition column, and an extra `organization_id` column.
 *
 * @property string|null $organization_id
 */
class LegacyEntry extends ChainEntry
{
    use HasUlids;

    protected $table = 'legacy_audit_logs';

    public function chainPartitionColumn(): string
    {
        return 'environment_id';
    }
}
