---
title: Security
weight: 50
description: The threat model, the cryptography used, and how to report a vulnerability
---

# Security

- **[Threat model](threat-model.md)**: who this defends against, how, and where it stops.
- **[Least privilege](least-privilege.md)**: run the app as a role that can only read and
  append, with the exact grants per engine and lock strategy.

## Cryptography

- **Hashing:** SHA-256 (PHP `hash()`), over canonical bytes defined in
  [The hash format](../core-concepts/hash-format.md).
- **Signatures:** Ed25519 (RFC 8032) through libsodium (`ext-sodium`). No cryptography is
  implemented in this package. The suite checks libsodium against RFC 8032 test 1 and
  pins the package's token format with a known-answer test.
- **Comparisons** of hashes and signatures use `hash_equals` / libsodium's constant-time
  verification.

## Reporting a vulnerability

Report privately through GitHub Private Vulnerability Reporting on the repository
(Security → Report a vulnerability). See [SECURITY.md](../../SECURITY.md).
