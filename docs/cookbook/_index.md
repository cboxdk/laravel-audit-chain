---
title: Cookbook
weight: 30
description: Task-oriented recipes — anchoring to R2, adopting an existing chain, rotating keys, scheduling
---

# Cookbook

- **[Anchor checkpoints to R2](anchor-checkpoints-to-r2.md)**: export every signed
  checkpoint to a Cloudflare R2 (or S3) bucket your database's writers cannot touch.
- **[Adopt an existing chain](adopt-an-existing-chain.md)**: take over verifying and
  extending a hash chain another implementation wrote, without rewriting a row.
- **[Rotate signing keys](rotate-signing-keys.md)**: switch to a new checkpoint key
  without invalidating the checkpoints the old one signed.
- **[Schedule checkpoints and verification](schedule-checkpoints.md)**: the two opt-in
  scheduled passes, and when to turn each on.
- **[Verify from outside the application](verify-offline.md)**: re-check an exported
  checkpoint with nothing but its public key.
