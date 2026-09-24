---
title: Checkpoints
weight: 23
description: What a signed checkpoint detects, the acp1 token format, key rotation, and why the first checkpoint is a one-way door
---

# Checkpoints

## What they are for

The chain detects a changed entry, a missing entry in the middle, and reordering. It
cannot detect **truncation**: delete the newest N entries and what remains is a
shorter chain that verifies perfectly.

A checkpoint closes that gap. It is a signed statement: "chain `(partition, scope)` had
hash `H` at sequence `N`, as of time `T`". `verify()` reads the newest checkpoint for the
chain and requires, in order:

1. its signature verifies against a trusted key, or the chain is reported as
   `checkpoint signature failed to verify`;
2. the signed claims match the stored row: same scope, same partition (when the signer
   binds one), same sequence, same root hash. Otherwise:
   `checkpoint payload does not match its signature`;
3. the entry at sequence `N` still exists with hash `H`. Otherwise:
   `entries at or below the last checkpoint were removed or altered`.

Step 2 matters. Someone who truncates the chain can also rewrite the checkpoint row to
describe the shorter chain; only the signature disagrees with them.

A chain with no checkpoint has no truncation detection at all.

## Signing them

```bash
php artisan audit-chain:checkpoint --dry-run    # what would be signed, per chain
php artisan audit-chain:checkpoint              # sign every chain that advanced
php artisan audit-chain:checkpoint --partition=tenant_a --scope=billing
php artisan audit-chain:checkpoint --force      # re-sign an unchanged head
```

Or in code: `app(AuditChain::class)->checkpoint($key)` for one chain,
`app(Checkpointer::class)->checkpointAll()` for all of them.

The pass is **idempotent**: a chain whose head is already attested is skipped. It takes
no lock and writes no entry, so it is safe alongside live traffic. An append that lands
mid-pass belongs to the next checkpoint. A chain that cannot be signed (a missing key
for its tenant, say) is reported and the pass carries on; the command then exits
non-zero.

Each chain is signed inside `ChainContext::run()`, so a host whose keys or row
ownership follow an ambient tenant signs each chain as its own tenant. See
[Chain context](../extension-points/chain-context.md).

## The first checkpoint is a one-way door

`audit-chain.checkpoint.schedule` defaults to `false`, on purpose.

A checkpoint attests the chain's hashes as they are today, and once it has been
exported you cannot take it back. If you later need to **re-chain** (change the codec,
or hash the ciphertext of personal data instead of its plaintext so that destroying a
per-subject key erases the data without breaking the chain), every checkpoint signed
before the re-chain will report tampering that never happened.

So, in this order:

1. Decide whether a re-chain is ahead of you. If one is, do it first (and sign and keep
   the pre-re-chain head hashes out of band as your own evidence).
2. Then turn the schedule on:

```dotenv
AUDIT_CHAIN_CHECKPOINT_SCHEDULE=true
AUDIT_CHAIN_CHECKPOINT_TIME=02:40
```

If no re-chain is ahead of you, turn it on now: until a chain is checkpointed, deleting
its newest entries is not detectable.

## The `acp1` token

The default signer (`Signing\Ed25519CheckpointSigner`) produces:

```
acp1.<kid>.<base64url(payload)>.<base64url(signature)>
```

- `payload` is the canonical JSON of
  `{"iat", "partition", "root_hash", "scope", "typ": "audit-chain.checkpoint", "up_to_sequence"}`.
- `signature` is the 64-byte Ed25519 (RFC 8032) signature over the ASCII bytes
  `acp1.<kid>.<base64url(payload)>`. The format tag, the key id and the payload are all
  covered, so a token cannot be relabelled to another key.
- `kid` is 1 to 64 characters of `[A-Za-z0-9_-]`. base64url is unpadded, and only its one
  canonical spelling is accepted.

Signing and verification are `sodium_crypto_sign_detached` and
`sodium_crypto_sign_verify_detached`. There is no algorithm field: the class only speaks
Ed25519, so there is nothing for a token to negotiate or confuse. After the signature
verifies, the payload must be exactly that claim set, in canonical form, with
`typ` = `audit-chain.checkpoint`, a 64-character lowercase hex `root_hash`, and integer
`iat` and `up_to_sequence` (at least 1). Anything else is refused.

Because the token is self-contained, an exported copy can be re-verified with only the
public key, no database needed.

## Keys

- The active key is `signing.key_id` + `signing.secret_key` (a base64 32-byte seed, or
  the 64-byte libsodium secret key). Its public key is derived, so it always verifies
  its own tokens.
- Retired keys stay trusted through `signing.public_keys` (`kid => base64 public key`).
- Keys are decoded when used. A missing or malformed key fails the checkpoint that
  needs it, not every request.

See [Rotate signing keys](../cookbook/rotate-signing-keys.md).
