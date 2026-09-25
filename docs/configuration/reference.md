---
title: Reference
weight: 61
description: Every config/audit-chain.php key with its environment variable and default
---

# Configuration reference

| Key | Env | Default | Purpose |
|---|---|---|---|
| `storage.connection` | `AUDIT_CHAIN_DB_CONNECTION` | `null` (default connection) | Connection for the package's own models and migration. |
| `storage.tables.entries` | | `audit_chain_entries` | Entry table for the package's own model and migration. |
| `storage.tables.checkpoints` | | `audit_chain_checkpoints` | Checkpoint table. |
| `storage.partition_column` | | `partition_key` | Column holding a chain key's partition, on both tables. |
| `lock.driver` | `AUDIT_CHAIN_LOCK` | `anchor` | `anchor`, `advisory` (PostgreSQL only) or `auto`. See [Concurrency](../core-concepts/concurrency.md#lock-strategies). |
| `models.entry` | | `AuditChainEntry::class` | Entry model; must extend `Models\ChainEntry`. |
| `models.checkpoint` | | `AuditChainCheckpoint::class` | Checkpoint model; must extend `Models\ChainCheckpoint`. |
| `codec.extra_columns` | | `[]` | Host columns the default codec hashes under `extra`. Events may only write listed columns. |
| `signing.key_id` | `AUDIT_CHAIN_SIGNING_KEY_ID` | `null` | Active key id, 1-64 of `[A-Za-z0-9_-]`. |
| `signing.secret_key` | `AUDIT_CHAIN_SIGNING_SECRET_KEY` | `null` | Base64 Ed25519 seed (32 bytes) or libsodium secret key (64 bytes). |
| `signing.public_keys` | | `[]` | Retired keys still trusted for verification: `kid => base64 public key`. |
| `anchor.driver` | `AUDIT_CHAIN_ANCHOR` | `null` | `null` or `filesystem`. Bind `CheckpointAnchor` for anything else. |
| `anchor.disk` | `AUDIT_CHAIN_ANCHOR_DISK` | `null` (default disk) | Disk for the filesystem anchor. |
| `anchor.prefix` | `AUDIT_CHAIN_ANCHOR_PREFIX` | `audit-chain/checkpoints` | Path prefix inside the disk. |
| `checkpoint.schedule` | `AUDIT_CHAIN_CHECKPOINT_SCHEDULE` | `false` | Schedule `audit-chain:checkpoint` daily. Read the one-way-door note first. |
| `checkpoint.time` | `AUDIT_CHAIN_CHECKPOINT_TIME` | `02:40` | `HH:MM`; a malformed value falls back to the default. |
| `verify.schedule` | `AUDIT_CHAIN_VERIFY_SCHEDULE` | `false` | Schedule `audit-chain:verify --window=N` daily. Read-only. |
| `verify.time` | `AUDIT_CHAIN_VERIFY_TIME` | `03:10` | `HH:MM`. |
| `verify.window` | `AUDIT_CHAIN_VERIFY_WINDOW` | `1000` | Newest N entries per chain for the scheduled verify. |

## Commands

| Command | Does |
|---|---|
| `audit-chain:checkpoint [--partition=*] [--scope=*] [--force] [--dry-run]` | Sign every chain that advanced since its last checkpoint. Exits non-zero if any chain could not be signed. |
| `audit-chain:verify [--partition=*] [--scope=*] [--window=N]` | Verify every chain. Exits non-zero if any is broken. |
| `audit-chain:keygen [--kid=]` | Print a new Ed25519 key pair. Writes nothing. |
| `audit-chain:grants {role} [--host=%] [--lock=]` | Print least-privilege grants and append-only triggers for this app's tables. Runs nothing. |
