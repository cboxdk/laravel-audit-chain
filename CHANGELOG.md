# Changelog

All notable changes to `cboxdk/laravel-audit-chain` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
