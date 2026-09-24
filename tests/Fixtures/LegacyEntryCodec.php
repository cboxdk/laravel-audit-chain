<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests\Fixtures;

use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Models\ChainEntry;

/**
 * Reproduces, byte for byte, the canonical form an earlier implementation hashed:
 * a fixed field ORDER (not sorted), `environment_id` for the partition, the extra
 * `organization_id` column, context recursively `ksort`ed with PHP's default flags,
 * and `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *
 * This is the whole of what adopting an existing chain takes — see
 * docs/cookbook/adopt-an-existing-chain.md.
 */
class LegacyEntryCodec implements EntryCodec
{
    public function version(): string
    {
        return 'legacy/v1';
    }

    public function extraColumns(): array
    {
        return ['organization_id'];
    }

    public function canonicalize(ChainEntry $entry): string
    {
        return json_encode([
            'sequence' => $entry->sequence,
            'environment_id' => $entry->chainPartition(),
            'scope' => $entry->scope,
            'organization_id' => $entry->getAttribute('organization_id'),
            'actor_type' => $entry->actorTypeValue(),
            'actor_id' => $entry->actor_id,
            'action' => $entry->action,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            'context' => $this->sortRecursively($entry->context),
            'ip' => $entry->ip,
            'recorded_at' => $entry->recorded_at?->getTimestamp(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sortRecursively(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursively($value);
            }
        }

        return $data;
    }
}
