---
title: Architecture
weight: 21
description: The contracts, their default implementations, and how a host replaces any of them
---

# Architecture

Everything is a contract in `Cbox\AuditChain\Contracts`, bound to a default by
`AuditChainServiceProvider` with `singletonIf`. Depend on the contract; bind your own
to change behaviour.

| Contract | Default | Job |
|---|---|---|
| `AuditChain` | `DatabaseAuditChain` | `record()`, `verify()`, `head()`, `checkpoint()` |
| `EntryCodec` | `Codec\V1EntryCodec` | The exact bytes an entry's hash covers |
| `CheckpointSigner` | `Signing\Ed25519CheckpointSigner` | Sign and verify checkpoint claims |
| `CheckpointAnchor` | `Anchoring\NullCheckpointAnchor` (or `FilesystemCheckpointAnchor` by config) | Export each signed checkpoint |
| `ChainContext` | `Support\PassthroughChainContext` | Run sweep work "as" a chain |
| `ChainInventory` | `Storage\DatabaseChainInventory` | List stored chains and their heads |

Two services build on those:

- `Checkpointer` signs every chain that advanced since its last checkpoint
  (`audit-chain:checkpoint`).
- `ChainVerifier` verifies every chain, or the newest N entries of each
  (`audit-chain:verify`).

## Storage

`Storage\ChainModels` names the two Eloquent models a chain uses. By default it reads
`audit-chain.models.*` and the models read `audit-chain.storage.*`. The models extend
two abstract bases:

- `Models\ChainEntry`: the columns the chain maintains, the casts, and
  `chainPartitionColumn()`.
- `Models\ChainCheckpoint`: the same for checkpoints.

A host that keeps its own tables writes two small models over them and passes a
`ChainModels` to a `DatabaseAuditChain` it constructs itself. That leaves the package
config, and any other chain in the app, untouched. See
[Your own models](../extension-points/models.md).

Every chain query drops the model's global scopes and states its own
`partition = ? AND scope = ?` predicate. A chain read through an ambient tenant scope
that matches nothing would see an empty chain and restart at sequence 1, which is the
one failure a tamper-evident log must never have.

## Value objects

- `ChainKey(partition, scope)`: which chain. Either half may be empty; neither may contain a NUL byte.
- `ChainEvent(action, actor, targetType, targetId, context, ip, columns)` with a
  `ChainActor(type, id)`.
- `ChainVerification(valid, verifiedCount, brokenAtSequence, break)`, where `break` is a
  `ChainBreak` enum whose string values are stable.
- `CheckpointClaims`, `SignedCheckpoint`, `CheckpointOutcome`, `ChainHead`,
  `VerifiedChain`, `ChainFilter`.

Arrays appear only at serialisation boundaries: `context` (stored as JSON) and
`columns` (written to the row).

## Openness

No class is `final` (an architecture test enforces it), and every exception implements
`Exceptions\AuditChainException`.
