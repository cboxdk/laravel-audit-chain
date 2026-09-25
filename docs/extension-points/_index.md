---
title: Extension points
weight: 40
description: Replace the codec, the signer, the anchor, the chain context or the models through the contracts
---

# Extension points

Every capability is a contract bound with `singletonIf`. Bind your own implementation in
a service provider and it wins, whichever provider registers first.

- **[Entry codec](codec.md)**: what bytes are hashed.
- **[Checkpoint signer](signer.md)**: sign checkpoints with a key you already manage.
- **[Checkpoint anchor](anchor.md)**: export checkpoints somewhere other than a
  filesystem disk.
- **[Chain context](chain-context.md)**: sign and verify each chain inside its tenant's
  context.
- **[Your own models](models.md)**: keep the chain in tables you own.
- **[Chain lock](chain-lock.md)**: how appenders to one chain are serialised.

To wrap behaviour rather than replace it, decorate the contract:

```php
$this->app->extend(AuditChain::class, fn (AuditChain $inner) => new MetricsAuditChain($inner));
```
