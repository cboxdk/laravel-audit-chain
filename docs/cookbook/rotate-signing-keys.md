---
title: Rotate signing keys
weight: 33
description: Switch to a new checkpoint signing key without breaking the checkpoints the old key signed
---

# Rotate signing keys

Every checkpoint names the key that signed it (`kid`). Verification looks that key up
in the keyring, so rotating is: **add** a new active key, and **keep** the old public
key. Never delete a retired public key: every checkpoint it signed would start
reporting `checkpoint signature failed to verify`.

## Steps

1. Generate the new key:

   ```bash
   php artisan audit-chain:keygen --kid=acp-2027-01
   ```

2. Note the **old** key's public key. If you did not keep it from its `keygen` run,
   derive it from the old secret:

   ```bash
   php -r 'echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode(getenv("OLD_SECRET"))))), PHP_EOL;'
   ```

   (For a 64-byte secret key, use its first 32 bytes as the seed.)

3. In `config/audit-chain.php`, keep the old public key:

   ```php
   'signing' => [
       'key_id' => env('AUDIT_CHAIN_SIGNING_KEY_ID'),
       'secret_key' => env('AUDIT_CHAIN_SIGNING_SECRET_KEY'),
       'public_keys' => [
           'acp-20260924-1a2b3c4d' => 'j0x…=',   // retired, still verifies
       ],
   ],
   ```

4. Switch the environment to the new `AUDIT_CHAIN_SIGNING_KEY_ID` and
   `AUDIT_CHAIN_SIGNING_SECRET_KEY`, deploy, and run:

   ```bash
   php artisan audit-chain:verify --window=10
   php artisan audit-chain:checkpoint --force --dry-run
   ```

New checkpoints carry the new `kid`; old ones keep verifying through the keyring.

## If a key leaked

A leaked signing key lets someone sign checkpoints for a rewritten chain. Rotate as
above, then compare the chain against checkpoints exported **before** the leak (see
[Anchor checkpoints to R2](anchor-checkpoints-to-r2.md)): those copies were written
while the key was still yours. Removing the leaked key's public key from the keyring
makes every checkpoint it signed fail verification, which is correct if you cannot
tell its genuine checkpoints from forged ones, and noisy if you can. Decide which
before you remove it.
