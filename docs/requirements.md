---
title: Requirements
weight: 3
description: PHP, Laravel, extension and database requirements, taken from composer.json and CI
---

# Requirements

Taken from the package's `composer.json`; the resolver enforces them.

## Runtime

| Requirement | Version | Why |
|---|---|---|
| PHP | `^8.4` | Language features used throughout; CI runs 8.4 and 8.5. |
| ext-sodium | `*` | Ed25519 checkpoint signatures (`sodium_crypto_sign_*`). |
| ext-json | `*` | Canonical JSON for hashing and signing. |

## Framework

| Requirement | Version |
|---|---|
| `illuminate/console`, `illuminate/contracts`, `illuminate/database`, `illuminate/support` | `^12.0 \|\| ^13.0` |

There are no other runtime dependencies.

## Databases

The append path's correctness under concurrency depends on the engine, so this table
says only what has been run:

| Engine | Full test suite, including the 8-writer concurrency test |
|---|---|
| SQLite | Every run (default). The concurrency test needs a server engine and is skipped here. |
| PostgreSQL 16 | Run locally before 0.1.0; wired into CI's `engines` job. |
| MySQL 8.4 | Run locally before 0.1.0; wired into CI's `engines` job. |
| MariaDB 11.8 | Run locally before 0.1.0; wired into CI's `engines` job. |
| SQL Server, others | Never run. |

The [least-privilege](security/least-privilege.md) grants were measured and are tested on
the same three engines.

The CI workflow runs on GitHub-hosted runners, which only happens once the repository
is public. Until then the engine results above are from local runs.
