<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Models;

use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Eloquent\Model;

/**
 * The base every stored checkpoint model extends.
 *
 * A checkpoint row is a signed statement about one chain: its entry at
 * `up_to_sequence` had the hash `root_hash`. The row is a convenience copy — the
 * `signature` is what makes it evidence, and an exported copy (see
 * {@see CheckpointAnchor}) is what makes it evidence an
 * attacker with database access cannot rewrite.
 *
 * @property string $scope
 * @property int $up_to_sequence
 * @property string $root_hash
 * @property string $signature
 */
abstract class ChainCheckpoint extends Model
{
    protected $guarded = [];

    /**
     * The column that stores the chain key's partition.
     */
    abstract public function chainPartitionColumn(): string;

    /**
     * Stamp the chain's address on a new checkpoint. Override to derive host columns
     * from the key as well, calling the parent.
     */
    public function assignChainKey(ChainKey $key): void
    {
        $this->setAttribute($this->chainPartitionColumn(), $key->partition);
        $this->setAttribute('scope', $key->scope);
    }

    public function chainPartition(): string
    {
        $partition = $this->getAttribute($this->chainPartitionColumn());

        return is_scalar($partition) ? (string) $partition : '';
    }

    public function chainKey(): ChainKey
    {
        return new ChainKey($this->chainPartition(), $this->scope);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'up_to_sequence' => 'integer',
        ];
    }
}
