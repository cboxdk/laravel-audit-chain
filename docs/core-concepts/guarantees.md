---
title: Guarantees and limits
weight: 25
description: Tamper-evident not tamper-proof, integrity not completeness, and exactly what each control detects
---

# Guarantees and limits

## Tamper-evident, not tamper-proof

The chain makes changes **detectable**. It does not make them impossible.

| Change to stored data | Detected by | Without a checkpoint? |
|---|---|---|
| Edit any hashed column of an entry | its own hash | Yes |
| Edit `hash` or `prev_hash` | the hash check or the next entry's linkage | Yes |
| Delete or reorder an entry in the middle | sequence continuity and linkage | Yes |
| Move an entry to another chain | partition and scope are inside the hash | Yes |
| Delete the newest entries | the newest checkpoint's anchored entry | **No** |
| Wipe a whole chain | the newest checkpoint | **No** |
| Rewrite a checkpoint row to match a truncated chain | the checkpoint's signature | n/a |
| Recompute every hash after editing (full rewrite) | an exported checkpoint the attacker cannot reach | **No** |

The last row is the honest limit. Someone with write access to the table can recompute
the whole chain. Stored checkpoints in the same database do not stop them either: they
can delete those. What stops them is a copy of a signed checkpoint in a store they
cannot write, such as an object bucket with a retention lock, written with credentials
your database's writers do not hold. That is what the
[anchor](../cookbook/anchor-checkpoints-to-r2.md) is for. The signing key must also be
out of their reach: a key they can read lets them sign new checkpoints.

## Integrity, not completeness

The chain proves that what was recorded has not changed since. It cannot prove that
everything that happened was recorded. An event your code never passed to `record()`,
or a `record()` whose exception your code swallowed, leaves no gap in the chain.
Logging coverage is your application's job; the package makes the failure loud
(`CannotAppendToChain` is thrown, never swallowed) so it can be.

## Verification scope

- `verify($key, $from)` re-hashes the rows in the window and checks the newest
  checkpoint. A window catches changes inside it, links into it, and truncation below
  the newest checkpoint. It does not re-hash rows before `$from`: schedule a periodic
  full pass as well.
- Only the **newest** checkpoint is checked. Older checkpoints are kept, but not
  re-verified.
- Verification reads the database. It is only as independent as the data it reads:
  for an independent check, verify exported checkpoints against a copy of the chain.

## Time

`recorded_at` is the application server's clock, in whole seconds. The chain orders
entries by sequence, not time, and does not prove when an event happened. Checkpoint
`iat` is the signing server's clock.

## Retention

Entries are append-only. Deleting old entries breaks verification: a full pass reports a
gap at the first missing sequence, and deleting the entry the newest checkpoint names
reports truncation. There is no prune-and-keep-verifying
mechanism in this package. If you need to bound growth, export and archive first, and
plan the verification story for the archived part before deleting anything.
