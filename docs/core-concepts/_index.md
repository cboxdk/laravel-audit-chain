---
title: Core concepts
weight: 20
description: How the chain is built, what is hashed, how checkpoints work, how concurrent appends stay safe, and what it all guarantees
---

# Core concepts

- **[Architecture](architecture.md)**: the contracts, the default implementations, and
  how they fit together.
- **[The hash format](hash-format.md)**: exactly which bytes are hashed, byte for byte.
- **[Checkpoints](checkpoints.md)**: what they detect, the `acp1` token format, and why
  the first one is a one-way door.
- **[Concurrency](concurrency.md)**: how parallel appends to one chain stay gapless on
  PostgreSQL, MySQL and MariaDB.
- **[Guarantees and limits](guarantees.md)**: tamper-evident, not tamper-proof;
  integrity, not completeness.
