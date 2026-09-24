<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Codec;

use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Models\ChainEntry;

/**
 * The package's canonical entry form, `audit-chain/v1`.
 *
 * The canonical bytes of an entry are the {@see CanonicalJson} encoding of:
 *
 *     {
 *       "action":      string,
 *       "actor_id":    string|null,
 *       "actor_type":  string,
 *       "codec":       "audit-chain/v1",
 *       "context":     object|array  (canonical, recursively key-sorted),
 *       "extra":       object        (only when extra columns are configured),
 *       "ip":          string|null,
 *       "partition":   string,
 *       "recorded_at": int           (unix seconds),
 *       "scope":       string,
 *       "sequence":    int,
 *       "target_id":   string|null,
 *       "target_type": string|null
 *     }
 *
 * with keys in byte order. `codec` is inside the hash so a future canonical form can
 * never produce the same bytes for a different reading of the same row.
 *
 * Both halves of the chain key are hashed: an entry cannot be moved to another chain
 * by rewriting its partition or scope column.
 *
 * FROZEN. Changing what this class emits breaks every chain it ever wrote; a new form
 * is a new codec. tests/Unit/V1EntryCodecTest.php pins it against committed vectors.
 */
class V1EntryCodec implements EntryCodec
{
    public const VERSION = 'audit-chain/v1';

    /**
     * @param  list<string>  $extraColumns  host columns to hash under `extra`
     */
    public function __construct(
        private readonly array $extraColumns = [],
    ) {}

    public static function fromConfig(): self
    {
        $configured = config('audit-chain.codec.extra_columns', []);
        $columns = [];

        foreach (is_array($configured) ? $configured : [] as $column) {
            if (is_string($column) && $column !== '') {
                $columns[] = $column;
            }
        }

        return new self(array_values(array_unique($columns)));
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function extraColumns(): array
    {
        return $this->extraColumns;
    }

    public function canonicalize(ChainEntry $entry): string
    {
        $payload = [
            'action' => $entry->action,
            'actor_id' => $entry->actor_id,
            'actor_type' => $entry->actorTypeValue(),
            'codec' => self::VERSION,
            'context' => $entry->context,
            'ip' => $entry->ip,
            'partition' => $entry->chainPartition(),
            'recorded_at' => $entry->recorded_at?->getTimestamp(),
            'scope' => $entry->scope,
            'sequence' => $entry->sequence,
            'target_id' => $entry->target_id,
            'target_type' => $entry->target_type,
        ];

        if ($this->extraColumns !== []) {
            $extra = [];

            foreach ($this->extraColumns as $column) {
                $extra[$column] = $entry->getAttribute($column);
            }

            $payload['extra'] = $extra;
        }

        return CanonicalJson::encode($payload);
    }
}
