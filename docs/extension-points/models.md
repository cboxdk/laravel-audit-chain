---
title: Your own models
weight: 45
description: Keep chains in tables you own, with your own partition column and extra columns
---

# Your own models

There are three levels of customisation, from least to most.

## 1. Configure the package's tables

`audit-chain.storage.connection`, `.tables.entries`, `.tables.checkpoints` and
`.partition_column` move the package's own tables. The published migration reads the
same keys, so set them before you migrate.

## 2. Subclass the package's models

Add relations, accessors or scopes, and point the config at your classes:

```php
class AuditEntry extends \Cbox\AuditChain\Models\AuditChainEntry
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'partition_key');
    }
}
```

```php
'models' => ['entry' => App\Models\AuditEntry::class, 'checkpoint' => …],
```

A global scope on your model is fine: every chain query drops global scopes and states
its own predicate. Other code reading your model keeps its scopes.

## 3. Models over tables you own

Extend the abstract bases and state the table and partition column the ordinary way:

```php
class LedgerEntry extends \Cbox\AuditChain\Models\ChainEntry
{
    use HasUlids;

    protected $table = 'ledger_audit';

    public function chainPartitionColumn(): string
    {
        return 'tenant_id';
    }
}
```

Then build the chain over them. Constructing it directly, rather than through
`audit-chain.models.*`, keeps a published config from ever changing what it reads:

```php
$models = new ChainModels(LedgerEntry::class, LedgerCheckpoint::class);

$this->app->singleton(AuditChain::class, fn ($app) => new DatabaseAuditChain(
    $models, $app->make(EntryCodec::class), $app->make(CheckpointSigner::class), $app->make(CheckpointAnchor::class),
));
$this->app->singleton(ChainInventory::class, fn () => new DatabaseChainInventory($models));
```

The table needs the columns listed on `ChainEntry`, `unique(partition, scope, sequence)`
(the append's retry ladder depends on it), and a NOT NULL partition column: SQL treats
NULLs as distinct in a unique index, so a NULL partition makes the constraint inert.

### Extra columns

- **Per event**: `ChainEvent::withColumns(['request_id' => $id])`. The codec must list
  the column in `extraColumns()`.
- **Derived from the key**: override `assignChainKey()` on the model, call the parent,
  and set the column. Hash it if it should be tamper-evident.
