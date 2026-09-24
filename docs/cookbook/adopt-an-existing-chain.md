---
title: Adopt an existing chain
weight: 32
description: Verify and extend a hash chain another implementation wrote, through a custom codec and your own models, without rewriting a row
---

# Adopt an existing chain

You already have a hash-chained audit table, written by code you are replacing. You
want this package to verify it and keep appending to it, without migrating or
re-hashing a single row, because every existing checkpoint and every exported copy
attests the hashes as they are.

Three things have to match what the old code did: the **table**, the **canonical
bytes**, and the **hash composition**. The package fixes the composition
(`SHA-256(canonical ‖ prev_hash)`, 64 hex zeros for genesis), so check that your old
implementation used the same. The other two you provide.

This package's own suite does exactly this against real rows written by an earlier
implementation: `tests/Feature/AdoptExistingChainTest.php`, with the codec and models in
`tests/Fixtures/`.

## 1. Models over your tables

```php
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class LegacyEntry extends ChainEntry
{
    use HasUlids;

    protected $table = 'audit_logs';

    public function chainPartitionColumn(): string
    {
        return 'environment_id';
    }
}

class LegacyCheckpoint extends ChainCheckpoint
{
    use HasUlids;

    protected $table = 'audit_checkpoints';

    public function chainPartitionColumn(): string
    {
        return 'environment_id';
    }

    // A column the old schema derived from the chain key.
    public function assignChainKey(ChainKey $key): void
    {
        parent::assignChainKey($key);
        $this->setAttribute('organization_id', $key->scope === '__system__' ? null : $key->scope);
    }
}
```

The chain maintains `scope`, `sequence`, `actor_type`, `actor_id`, `action`,
`target_type`, `target_id`, `context`, `ip`, `prev_hash`, `hash` and `recorded_at`. If
your table names them differently, map them with accessors and mutators on the model.
If it has a column the chain does not know about (`organization_id` above), that is an
**extra column**.

## 2. A codec that reproduces the old bytes exactly

```php
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Models\ChainEntry;

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
        // Same keys, same ORDER, same flags as the code that wrote the rows.
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

    private function sortRecursively(array $data): array
    {
        ksort($data); // the OLD sort flags, whatever they were

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursively($value);
            }
        }

        return $data;
    }
}
```

Every detail matters: key order, sort flags, JSON flags, how null and time are
rendered. Get one wrong and every existing row fails verification.

## 3. Wire the chain

Construct the chain explicitly rather than through config, so nothing else in the app
(and no published config) can change what it reads:

```php
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\DatabaseAuditChain;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\Storage\DatabaseChainInventory;

$models = new ChainModels(LegacyEntry::class, LegacyCheckpoint::class);

$this->app->singleton(AuditChain::class, fn ($app) => new DatabaseAuditChain(
    $models,
    new LegacyEntryCodec,
    $app->make(CheckpointSigner::class), // or an adapter over your existing signer
    $app->make(CheckpointAnchor::class),
));

$this->app->singleton(ChainInventory::class, fn () => new DatabaseChainInventory($models));
```

To record into the extra column, pass it on the event. The chain refuses a column the
codec does not declare:

```php
$chain->record($key, ChainEvent::system('user.login')->withColumns(['organization_id' => $orgId]));
```

## 4. Existing checkpoints

If the old code signed checkpoints in its own format, write a `CheckpointSigner` that
adapts it: `verify()` parses and verifies the old token (pinning its algorithms) and
returns `CheckpointClaims`, and `sign()` produces the same format. If the old format did
not bind the partition, return claims with `partition: null`; verification then holds
scope, sequence and root hash to the row and does not require a partition. See
[Custom signer](../extension-points/signer.md).

## 5. Prove it before you switch

Before the new code writes anything in production:

1. Export a sample of real rows (every partition shape, unicode, nested context, nulls)
   together with the inputs that produced them, using the **old** implementation.
2. With the new wiring, `verify()` those exact stored rows: every chain must pass with
   the same counts.
3. Replay the inputs into an empty table with the clock frozen at each original time:
   every `sequence`, `prev_hash` and `hash` must match the stored ones.

That is the evidence that the switch changed nothing.
