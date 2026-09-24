---
title: Quickstart
weight: 2
description: Install the package, record an event, verify the chain and sign a checkpoint
---

# Quickstart

## 1. Install

```bash
composer require cboxdk/laravel-audit-chain
php artisan vendor:publish --tag=audit-chain-config
php artisan vendor:publish --tag=audit-chain-migrations
php artisan migrate
```

The service provider is auto-discovered. The migration creates `audit_chain_entries` and
`audit_chain_checkpoints` (names and connection come from `config/audit-chain.php`, so
change them before you migrate if you need to).

## 2. Record

```php
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;

$chain = app(AuditChain::class);

$entry = $chain->record(
    ChainKey::of($tenant->id, 'billing'),
    ChainEvent::by(ChainActor::of('user', $user->id), 'invoice.voided', ['reason' => 'duplicate'])
        ->on('invoice', $invoice->id)
        ->from($request->ip()),
);

$entry->sequence; // 1, 2, 3, … per chain
$entry->hash;     // SHA-256 over the entry and the previous hash
```

`record()` is safe under concurrency: parallel writers to one chain never lose an entry
or share a position. See [Concurrency](core-concepts/concurrency.md).

## 3. Verify

```php
$result = $chain->verify(ChainKey::of($tenant->id, 'billing'));

$result->valid;            // false if anything was changed, removed or reordered
$result->brokenAtSequence; // where
$result->reason;           // e.g. "content hash mismatch (tampered)"
```

Or everything at once:

```bash
php artisan audit-chain:verify
php artisan audit-chain:verify --window=1000   # just the newest 1000 entries per chain
```

## 4. Sign checkpoints

A chain alone cannot see its newest entries being deleted. A signed checkpoint can.

```bash
php artisan audit-chain:keygen          # prints AUDIT_CHAIN_SIGNING_KEY_ID / _SECRET_KEY
php artisan audit-chain:checkpoint --dry-run
php artisan audit-chain:checkpoint
```

Before you schedule it (`AUDIT_CHAIN_CHECKPOINT_SCHEDULE=true`), read
[why the first checkpoint is a one-way door](core-concepts/checkpoints.md#the-first-checkpoint-is-a-one-way-door).
Then export checkpoints somewhere your database's writers cannot reach:
[Anchor checkpoints to R2](cookbook/anchor-checkpoints-to-r2.md).

## 5. Test with the fake

```php
use Cbox\AuditChain\Testing\InteractsWithAuditChain;

uses(InteractsWithAuditChain::class);

it('audits a voided invoice', function () {
    $audit = $this->fakeAuditChain();

    // … exercise your code …

    $audit->assertRecorded('invoice.voided');
});
```

See [Testing](getting-started/testing.md).
