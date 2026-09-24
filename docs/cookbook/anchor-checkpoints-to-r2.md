---
title: Anchor checkpoints to R2
weight: 31
description: Export every signed checkpoint to a Cloudflare R2 or S3 bucket with a retention lock, using the filesystem anchor
---

# Anchor checkpoints to R2

Goal: every checkpoint, as it is signed, also lands in a bucket that the application's
database credentials cannot reach and that nobody can overwrite. Someone who later
rewrites `audit_chain_entries` and `audit_chain_checkpoints` cannot rewrite those copies.

The package's `FilesystemCheckpointAnchor` writes to any Laravel filesystem disk. R2
speaks the S3 API, so it is an `s3` disk.

## 1. The bucket

In Cloudflare, create a bucket (e.g. `audit-anchors`) and add a **bucket lock rule**
(R2 → bucket → Settings → Bucket lock rules) that retains objects under your prefix for
as long as you keep audit evidence. Locked objects cannot be deleted or overwritten
until the rule expires.

Create an R2 API token scoped to **that bucket only**, with object read and write.
Keep it separate from anything else the application uses. On AWS S3 the equivalent is
Object Lock in compliance mode plus an IAM policy that allows `s3:PutObject` and
`s3:GetObject` on the prefix and nothing else.

## 2. The disk

`composer require league/flysystem-aws-s3-v3` (the S3 driver for Laravel's filesystem;
your application's dependency, not this package's), then in `config/filesystems.php`:

```php
'audit-anchors' => [
    'driver' => 's3',
    'key' => env('AUDIT_ANCHOR_R2_KEY'),
    'secret' => env('AUDIT_ANCHOR_R2_SECRET'),
    'region' => 'auto',
    'bucket' => env('AUDIT_ANCHOR_R2_BUCKET', 'audit-anchors'),
    'endpoint' => env('AUDIT_ANCHOR_R2_ENDPOINT'), // https://<account-id>.r2.cloudflarestorage.com
    'use_path_style_endpoint' => true,
    'throw' => true,
],
```

`throw => true` matters: a failed write must raise, so the checkpoint is rolled back
and retried on the next pass instead of being recorded as anchored.

## 3. Point the anchor at it

```dotenv
AUDIT_CHAIN_ANCHOR=filesystem
AUDIT_CHAIN_ANCHOR_DISK=audit-anchors
AUDIT_CHAIN_ANCHOR_PREFIX=audit-chain/checkpoints
```

From now on every `checkpoint()` (from the command, the scheduler or your code) writes

```
audit-chain/checkpoints/<partition>/<scope>/<sequence, 20 digits>-<16 hex>.json
```

containing:

```json
{"checkpoint_id":"01J…","format":"audit-chain.anchor/v1","issued_at":1788256800,"partition":"tenant_a","root_hash":"…","scope":"billing","token":"acp1.…","up_to_sequence":42}
```

Path segments are percent-encoded (dots too), so no partition or scope can escape its
directory.

## 4. Check it

```bash
php artisan audit-chain:checkpoint --partition=tenant_a
```

then list the bucket. The `token` in each document re-verifies on its own; see
[Verify from outside the application](verify-offline.md).

## How failure behaves

- The export runs inside the transaction that stores the checkpoint row. If R2 is
  unreachable, `CannotAnchorCheckpoint` is thrown, the row is rolled back, the command
  reports that chain as failed and exits non-zero, and the next pass tries again.
- An existing document with identical content is left alone, so a retried export is
  harmless. A **different** document at the same path is refused, never overwritten.
- The package checks before it writes, but on S3 and R2 "check, then write" is not
  atomic. The real append-only guarantee is the bucket lock, not this class.

## What this does not protect against

- An attacker who also holds the R2 token can write documents (not overwrite locked
  ones). Keep the token out of the database and away from the application's other
  secrets where you can.
- An attacker who holds the **signing key** can sign checkpoints for a rewritten chain.
  The anchor proves what existed at each export; it cannot tell a forged later
  checkpoint from a real one if the key has leaked. Protect the key, and rotate it
  if in doubt ([Rotate signing keys](rotate-signing-keys.md)).
