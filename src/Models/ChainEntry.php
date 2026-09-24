<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Models;

use BackedEnum;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * The base every audit entry model extends — the package's own
 * {@see AuditChainEntry}, or a host's model over its own table.
 *
 * The chain maintains these columns (a host model may add more, see
 * {@see EntryCodec::extraColumns()}): the partition column named by
 * {@see chainPartitionColumn()}, `scope`, `sequence`, `actor_type`, `actor_id`,
 * `action`, `target_type`, `target_id`, `context` (JSON), `ip`, `prev_hash`, `hash`
 * and `recorded_at`.
 *
 * Append-only by contract: nothing in this package updates or deletes an entry, and
 * nothing should. A row that changes after it is written is exactly what verification
 * exists to report.
 *
 * @property string $scope
 * @property int $sequence
 * @property mixed $actor_type a string, or a backed enum when the host model casts it
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed> $context
 * @property string|null $ip
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon|null $recorded_at
 */
abstract class ChainEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * The column that stores the chain key's partition.
     */
    abstract public function chainPartitionColumn(): string;

    /**
     * Stamp the chain's address on a new entry.
     *
     * Override to derive host columns from the key as well — but call the parent, and
     * remember the codec must hash anything that should be tamper-evident.
     */
    public function assignChainKey(ChainKey $key): void
    {
        $this->setAttribute($this->chainPartitionColumn(), $key->partition);
        $this->setAttribute('scope', $key->scope);
    }

    /**
     * The partition this entry is stored under, exactly as stored.
     */
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
     * The actor type as the string that is stored — whether or not the host model
     * casts the column to an enum.
     */
    public function actorTypeValue(): string
    {
        $type = $this->getAttribute('actor_type');

        return match (true) {
            $type instanceof BackedEnum => (string) $type->value,
            $type instanceof UnitEnum => $type->name,
            is_scalar($type) => (string) $type,
            default => '',
        };
    }

    /**
     * Columns a host may not set through an event: the ones the chain itself writes.
     *
     * @return list<string>
     */
    public function chainColumns(): array
    {
        return [
            $this->chainPartitionColumn(), 'scope', 'sequence', 'actor_type', 'actor_id', 'action',
            'target_type', 'target_id', 'context', 'ip', 'prev_hash', 'hash', 'recorded_at',
            $this->getKeyName(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'context' => 'array',
            'recorded_at' => 'datetime',
        ];
    }
}
