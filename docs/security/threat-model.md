---
title: Threat model
weight: 51
description: What the chain, checkpoints and anchors defend against, and what they cannot
---

# Threat model

## Assets

- The audit entries: that what they say is what was recorded.
- The checkpoints: that they are genuine statements by the signing key's holder.
- The signing key.

## Adversaries and controls

| Adversary | Can | Control | Result |
|---|---|---|---|
| Someone with SQL write access (a compromised app credential, a malicious DBA, an injection) | Edit, delete or insert rows | Hash chain | Edits, gaps, reordering and moved rows are detected |
| Same, deleting the newest entries | Truncate a chain | Signed checkpoint | Detected once the chain has a checkpoint |
| Same, also rewriting the checkpoint row | Make the row describe the truncated chain | Checkpoint signature | Detected: the signed claims disagree with the row |
| Same, rewriting the whole chain and deleting checkpoints | Present a fully recomputed, consistent chain | Anchored checkpoints in a store they cannot write | Detected by comparing against the anchored copies; **not** detected without them |
| Someone holding the signing key | Sign checkpoints for a rewritten chain | Checkpoints anchored before the compromise, key rotation | Earlier anchored copies still contradict the rewrite; later ones cannot be told apart |
| Someone who controls the application code | Record false events, or skip recording | None | Out of scope: the chain proves integrity, not truthfulness or completeness |
| Concurrent writers (not malicious) | Race for the same position | Anchor lock, unique key, retry ladder | No lost or duplicated position ([Concurrency](../core-concepts/concurrency.md)) |

## What this package does not provide

- **Tamper-proof storage.** Rows live in your database. The package makes changes
  detectable, not impossible.
- **An external store.** The filesystem anchor writes wherever you point it; the
  append-only guarantee has to come from that store (object lock, retention rules) and
  from keeping its credentials apart from the database's.
- **Key management.** The signing key comes from your configuration. Protect it like
  any other credential, and rotate it if in doubt.
- **Trusted time.** `recorded_at` and `iat` come from the application servers' clocks.
- **Completeness.** It cannot know about events nobody recorded.
- **Confidentiality.** Entries are stored in plaintext. Keep secrets and unnecessary
  personal data out of `context`.

## Operational guidance

- Turn checkpoints on, after the [one-way-door decision](../core-concepts/checkpoints.md#the-first-checkpoint-is-a-one-way-door).
- Anchor them to a locked bucket with separate credentials.
- Schedule `audit-chain:verify` and alert on a non-zero exit.
- Give the application's database user `INSERT` and `SELECT` on the entry table and no
  `UPDATE` or `DELETE` where your engine allows it. The package never updates or
  deletes entries.
