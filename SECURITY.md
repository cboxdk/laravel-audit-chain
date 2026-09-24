# Security Policy

Cbox Audit Chain is a tamper-evidence control. A flaw in it can let changes to an audit
trail go unnoticed, so we take reports seriously.

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.**

Report privately through **GitHub Private Vulnerability Reporting**:
[Report a vulnerability](https://github.com/cboxdk/laravel-audit-chain/security/advisories/new)
(repository → **Security** → **Report a vulnerability**).

Please include:

- the affected version or commit and component (append path, verification, checkpoint
  signing, anchoring, a codec),
- a description of the issue and its impact,
- reproduction steps or a proof of concept,
- any suggested fix.

## What to expect

This is an open-source project maintained on a best-effort basis. We will respond as
promptly as we can, keep you informed while we investigate, and credit you when a fix
ships unless you prefer otherwise. We coordinate the timing of any public disclosure
with you.

## Supported versions

Before 1.0, only the latest tagged release receives security fixes.

## Scope

In scope: anything that lets a change to stored entries or checkpoints pass
verification, lets an append be lost or placed at a taken position, lets a checkpoint
token verify when it should not, or lets the anchor overwrite or misplace a document.

Documented limits are not vulnerabilities: the chain is tamper-evident rather than
tamper-proof, proves integrity rather than completeness, and cannot protect against
someone who holds the signing key. See
[docs/core-concepts/guarantees.md](docs/core-concepts/guarantees.md) and
[docs/security/threat-model.md](docs/security/threat-model.md).

## Disclosure history

Confirmed vulnerabilities and their fixes are published as
[GitHub Security Advisories](https://github.com/cboxdk/laravel-audit-chain/security/advisories)
and noted under **Security** in [CHANGELOG.md](CHANGELOG.md).
