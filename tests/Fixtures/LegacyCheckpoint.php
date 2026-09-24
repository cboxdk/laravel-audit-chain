<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests\Fixtures;

use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * @property string|null $organization_id
 */
class LegacyCheckpoint extends ChainCheckpoint
{
    use HasUlids;

    public const SYSTEM_SCOPE = '__system__';

    protected $table = 'legacy_audit_checkpoints';

    public function chainPartitionColumn(): string
    {
        return 'environment_id';
    }

    /**
     * The legacy schema also records the organization a checkpoint belongs to, which
     * in that design is simply the scope (or none, for the system trail).
     */
    public function assignChainKey(ChainKey $key): void
    {
        parent::assignChainKey($key);

        $this->setAttribute('organization_id', $key->scope === self::SYSTEM_SCOPE ? null : $key->scope);
    }
}
