---
title: Entry codec
weight: 41
description: Bind your own EntryCodec to control exactly which bytes are hashed
---

# Entry codec

```php
interface EntryCodec
{
    public function version(): string;          // e.g. 'acme/v1'
    public function extraColumns(): array;      // host columns this codec hashes
    public function canonicalize(ChainEntry $entry): string;
}
```

The chain hashes `SHA-256(canonicalize(entry) ‖ prev_hash)`. The codec is called on the
model right after it is filled (before insert) and after every read, so it must read
values through the model's attributes and casts, never from request state, config that
can change, or the clock.

## When to write one

- **Adopting a chain** another implementation wrote: reproduce its bytes exactly. See
  [Adopt an existing chain](../cookbook/adopt-an-existing-chain.md).
- **Hashing extra columns** in a specific shape. For plain extra columns the default
  codec already does it: list them in `audit-chain.codec.extra_columns`.
- **Hashing a transformed value**, such as the ciphertext of personal data instead of
  its plaintext, so that destroying a per-subject key erases the data without changing
  a single hashed byte.

## Rules

- **Deterministic.** Same row, same bytes, on every engine and PHP version you run.
- **Frozen once used.** Changing the output for existing rows breaks every chain. A new
  form is a new codec with a new `version()`.
- **Declare extra columns.** `extraColumns()` is what lets an event write them. The
  chain refuses to write a column the codec does not declare, because stored but
  unhashed means rewritable without detection.

## Binding

```php
$this->app->singleton(EntryCodec::class, AcmeEntryCodec::class);
```

That changes the codec of the default `AuditChain`. To give only one chain a different
codec, construct that `DatabaseAuditChain` yourself (see [Your own models](models.md)).
