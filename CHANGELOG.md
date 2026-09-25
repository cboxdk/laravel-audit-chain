# Changelog

All notable changes to `cboxdk/laravel-audit-chain` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ChainLock` contract: how appenders to one chain are serialised, selected by
  `audit-chain.lock.driver` (`AUDIT_CHAIN_LOCK`). `anchor` (default) is the existing
  anchor-row lock, unchanged statement for statement. `advisory` is a transaction-scoped
  PostgreSQL advisory lock per chain that needs no table privilege, pins READ COMMITTED for
  a transaction it owns, and is refused on other engines. `auto` is advisory on PostgreSQL
  and anchor elsewhere. `DatabaseAuditChain` takes an optional fifth argument; a hand-built
  chain without it keeps the anchor lock.
- Least privilege, measured and tested on PostgreSQL 16, MySQL 8.4 and MariaDB 11.8:
  `Security\LeastPrivilegeGrants` and `audit-chain:grants {role}` print the grants and
  append-only triggers per engine and strategy (they run nothing).
  `tests/Feature/LeastPrivilegeTest.php` appends, checkpoints, verifies and runs the
  8-writer forking test as a role that holds only those grants.

- `AuditChain` contract and `DatabaseAuditChain`: append-only, hash-chained entries in
  independent chains addressed by `ChainKey(partition, scope)`. Appends serialise on
  the chain's first entry, locked by primary key and found outside the transaction, with
  one retry ladder (8 attempts, jittered backoff) for duplicate keys and serialisation
  failures. Measured with 8 forked writers x 100 appends on PostgreSQL 16, MySQL 8.4 and
  MariaDB 11.8: 800/800, gapless, verifying.
- `EntryCodec` contract and the frozen `audit-chain/v1` canonical form (`V1EntryCodec`),
  with configurable extra hashed columns. An event may only write columns the codec
  hashes.
- `CheckpointSigner` contract and `Ed25519CheckpointSigner` (ext-sodium, `acp1` token
  format, key id, retired-key keyring). Verification checks the signature, the claims
  against the stored row, and that the attested entry is still present.
- `CheckpointAnchor` contract with `NullCheckpointAnchor` (default) and
  `FilesystemCheckpointAnchor` (any Laravel disk; append-only from its side, refuses to
  overwrite). An anchor failure rolls the checkpoint row back.
- `ChainContext` contract for sweeps that must run each chain in its own tenant context.
- `ChainInventory`, `Checkpointer` and `ChainVerifier`, with the `audit-chain:checkpoint`,
  `audit-chain:verify` and `audit-chain:keygen` commands. Both scheduled passes are
  opt-in; the checkpoint schedule defaults to off because the first signed checkpoint
  forecloses any later re-chain.
- Configurable models (`ChainEntry` / `ChainCheckpoint` bases), table names, connection
  and partition column, and a publishable migration.
- `FakeAuditChain` and `InteractsWithAuditChain` for tests.

### Fixed

- A runtime role holding only SELECT and INSERT on the chain tables (the least-privilege
  setup the docs recommend) could write each chain's first entry and was refused on every
  later one: the anchor lock is `SELECT … FOR UPDATE`, which PostgreSQL (SQLSTATE 42501)
  and MySQL 8 (error 1142) treat as needing UPDATE. Use the advisory lock on PostgreSQL, or
  grant UPDATE on the `hash` column behind an append-only trigger (MySQL 8, or PostgreSQL
  with the anchor lock). MariaDB is unaffected. Reported by the first consumer.

### Documented

- Measured isolation matrix: the anchor lock needs READ COMMITTED on PostgreSQL (at
  REPEATABLE READ or SERIALIZABLE, contended appends exhaust their retries, loudly), and
  MariaDB at SERIALIZABLE loses 2 of 800 contended appends to deadlocks past the budget.
  The advisory lock is 800/800 at every level on PostgreSQL.
