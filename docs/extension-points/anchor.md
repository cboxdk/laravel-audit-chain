---
title: Checkpoint anchor
weight: 43
description: Export signed checkpoints to your own append-only store
---

# Checkpoint anchor

```php
interface CheckpointAnchor
{
    public function anchor(SignedCheckpoint $checkpoint): void;
}
```

`SignedCheckpoint` carries the `ChainKey`, the `CheckpointClaims`, the signed `token` and
the stored row's `checkpointId`.

The package ships `NullCheckpointAnchor` (the default) and `FilesystemCheckpointAnchor`
(any Laravel disk; selected with `audit-chain.anchor.driver = filesystem`). Write your own
for a transparency log, a WORM appliance, a second database with separate credentials,
or a notarisation service.

## Contract

- It runs **inside the transaction** that stores the checkpoint row. Throw
  `CannotAnchorCheckpoint` on failure: the row is rolled back, and the next pass signs
  and exports again.
- Be **idempotent** for the same checkpoint (a retry must be harmless) and **never
  overwrite** a different one.
- Keep it quick. It holds a database transaction open, but only around one inserted row
  and no locks the append path needs.

## Example: two stores

```php
class FanOutAnchor implements CheckpointAnchor
{
    /** @param list<CheckpointAnchor> $anchors */
    public function __construct(private readonly array $anchors) {}

    public function anchor(SignedCheckpoint $checkpoint): void
    {
        foreach ($this->anchors as $anchor) {
            $anchor->anchor($checkpoint);
        }
    }
}

$this->app->singleton(CheckpointAnchor::class, fn ($app) => new FanOutAnchor([
    new FilesystemCheckpointAnchor(Storage::disk('r2-anchors')),
    new FilesystemCheckpointAnchor(Storage::disk('s3-anchors-eu')),
]));
```
