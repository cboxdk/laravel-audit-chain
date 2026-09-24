<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The package's default checkpoint model, over `audit_chain_checkpoints` unless
 * configured otherwise.
 */
class AuditChainCheckpoint extends ChainCheckpoint
{
    use HasUlids;
    use ReadsChainStorageConfig;

    public function getTable(): string
    {
        return $this->configuredTable('checkpoints', 'audit_chain_checkpoints');
    }
}
