---
title: Cbox Audit Chain
weight: 1
description: A tamper-evident, hash-chained audit trail for Laravel, with signed checkpoints and external anchoring
---

# Cbox Audit Chain

`cboxdk/laravel-audit-chain` is a tamper-evident, hash-chained audit trail for Laravel.
Every entry carries the SHA-256 of its own content and of the entry before it, so a
changed, removed or reordered entry is detectable. Signed checkpoints catch the one
thing a chain cannot see on its own (entries deleted off the end), and an anchor
exports each checkpoint to storage your database's writers cannot reach.

It is a library: contracts, an Eloquent implementation, two Artisan commands and a
test fake. No UI, no routes, no scheduled work unless you turn it on.

## The mental model

```
ChainKey(partition, scope) ──► chain of entries
                                 #1  hash = SHA-256(canonical(#1) ‖ 000…0)
                                 #2  hash = SHA-256(canonical(#2) ‖ hash#1)
                                 #3  hash = SHA-256(canonical(#3) ‖ hash#2)
                                         ▲
                     checkpoint: "#3 had hash#3", Ed25519-signed ──► anchor (S3, R2, …)
```

- A **chain** is addressed by a `ChainKey`: a `partition` and a `scope`. You decide what
  they mean (tenant and ledger, environment and organization). Each key is an
  independent chain with its own sequence.
- An **entry** is one recorded event: action, actor, target, context, IP, time.
- The **codec** turns an entry into the exact bytes that are hashed. The default is
  `audit-chain/v1`; you can bind your own.
- A **checkpoint** is a signed statement about a chain's head. Verification requires the
  entry it names to still be there, unchanged.
- An **anchor** exports each checkpoint somewhere append-only.

## What it guarantees, and what it does not

Tamper-**evident**, not tamper-**proof**. Someone who can rewrite the whole table can
recompute every hash; only checkpoints exported to a store they cannot write stop that.
The chain proves **integrity** (what was recorded has not changed), not
**completeness** (that everything that happened was recorded). Read
[Guarantees and limits](core-concepts/guarantees.md) before you rely on it.

## Documentation

- **[Quickstart](quickstart.md)**: install, record, verify, checkpoint.
- **[Requirements](requirements.md)**: PHP, Laravel, extensions, engines.
- **[Getting started](getting-started/_index.md)**: installation and testing with the fake.
- **[Core concepts](core-concepts/_index.md)**: architecture, the hash format, checkpoints,
  the concurrency model, guarantees and limits.
- **[Cookbook](cookbook/_index.md)**: anchoring to R2, adopting an existing chain, key
  rotation, scheduling.
- **[Extension points](extension-points/_index.md)**: codecs, signers, anchors, chain
  context, your own models.
- **[Configuration](configuration/_index.md)**: every config key.
- **[Security](security/_index.md)**: threat model and reporting.
