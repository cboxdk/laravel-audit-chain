---
title: Installation
weight: 11
description: Require the package, publish its config and migration, and set up a signing key
---

# Installation

```bash
composer require cboxdk/laravel-audit-chain
```

`Cbox\AuditChain\AuditChainServiceProvider` is registered by package discovery. It
binds every contract with `singletonIf`, so anything you bind yourself wins regardless
of provider order.

## Config

```bash
php artisan vendor:publish --tag=audit-chain-config
```

Decide these before the first entry is written. They are hard to change later:

- `storage.connection`, `storage.tables.*`, `storage.partition_column`: where the
  default models live. The migration reads the same keys.
- `codec.extra_columns`: host columns the default codec hashes. Changing the list
  changes the canonical bytes of entries that have those columns.

The full list is in [Configuration](../configuration/reference.md).

## Migration

```bash
php artisan vendor:publish --tag=audit-chain-migrations
php artisan migrate
```

The migration is **published, never auto-loaded**. An application that keeps its chain
in its own tables (see [Your own models](../extension-points/models.md)) does not get
an unused second pair.

## Signing key

Checkpoints are signed with Ed25519. Generate a key:

```bash
php artisan audit-chain:keygen
```

```
AUDIT_CHAIN_SIGNING_KEY_ID=acp-20260924-1a2b3c4d
AUDIT_CHAIN_SIGNING_SECRET_KEY=…
```

Put both in your environment or secret manager. The command prints and never writes,
so it cannot overwrite a key that existing checkpoints were signed with. Keep the
public key it prints: you need it in `signing.public_keys` when you
[rotate](../cookbook/rotate-signing-keys.md).

With no key configured, recording and verifying chains still work; signing a
checkpoint fails loudly (`CheckpointSigningUnavailable`) rather than signing with
nothing.

## Anchor

By default checkpoints are stored in the database only. For them to mean anything
against someone with database write access, export them:
[Anchor checkpoints to R2](../cookbook/anchor-checkpoints-to-r2.md).
