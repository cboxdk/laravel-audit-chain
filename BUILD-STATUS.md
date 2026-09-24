# Cbox Audit Chain — Build status

Living record of what is implemented, verified and pending. "Verified" means real tests
green + PHPStan level max clean + Pint clean. Nothing is marked done on inspection.

Legend: ✅ done & verified · 🔨 in progress · ⏳ pending · ⬜ not started

**Current (2026-09-24, unreleased 0.1.0):** everything below is implemented and verified on
SQLite, PostgreSQL 16, MySQL 8.4 and MariaDB 11.8 (local runs; the CI `engines` job runs
the same once the repository is public).

| Area | Status | Notes |
|---|---|---|
| `AuditChain` / `DatabaseAuditChain` | ✅ | Append (anchor lock by PK, found outside the transaction; one retry ladder for duplicate keys and 40001), verify (window-aware, checkpoint cross-check), head, checkpoint. |
| Concurrency | ✅ | 8 forked writers x 100 appends: 800/800 gapless and verifying on PG 16, MySQL 8.4, MariaDB 11.8. Every retry branch also covered on SQLite by injection. |
| `EntryCodec` / `V1EntryCodec` | ✅ | Frozen `audit-chain/v1`; known-answer vectors written from the docs, hashed independently. Extra columns gated by the codec. |
| `CheckpointSigner` / `Ed25519CheckpointSigner` | ✅ | `acp1` tokens via ext-sodium; RFC 8032 test 1 and a known-answer token pinned; retired-key keyring; strict claim parsing. |
| `CheckpointAnchor` | ✅ | Null (default) and filesystem (any disk, idempotent, never overwrites, rolls back the checkpoint on failure). |
| `ChainContext`, `ChainInventory` | ✅ | Passthrough and database defaults. |
| `Checkpointer`, `ChainVerifier`, commands | ✅ | `audit-chain:checkpoint`, `audit-chain:verify`, `audit-chain:keygen`. Both schedules opt-in; checkpoint schedule off by default (one-way door). |
| Configurable storage | ✅ | Models via config or `ChainModels`, tables, connection, partition column; publishable migration. |
| Adopting an existing chain | ✅ | Proven against real rows written by laravel-id v1.19.3 via a custom codec and host models. |
| Testing: `FakeAuditChain`, `InteractsWithAuditChain` | ✅ | Dogfooded in the suite's `TestCase`. |
| Docs | ✅ | Topic-folder layout, `_index.md` everywhere, frontmatter on every file (checked by `tests/Unit/DocsStructureTest.php`). |
| Supply chain | ✅ | `bin/check-licenses.php`, `bin/generate-sbom.php`, committed `sbom.json`. |
| CI | ⏳ | `.github/workflows/ci.yml` (PHP 8.4/8.5 x Laravel 12/13 on ubuntu-latest, plus the three engines). Runs once the repository is public. |

## Known gaps

- No release yet; `0.1.0` needs sign-off.
- Pest 5 exists on Packagist; the dev constraint stays `^3.5 || ^4.0` to match the
  sibling packages. Widen deliberately, together with them.
- SQL Server has never been run.
