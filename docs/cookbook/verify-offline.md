---
title: Verify from outside the application
weight: 35
description: Re-check an exported checkpoint, and a chain export against it, with nothing but PHP, ext-sodium and the public key
---

# Verify from outside the application

An anchored checkpoint is only useful if someone who does not trust your application
can check it. An `acp1` token needs nothing but the public key.

## The checkpoint

```php
<?php
// verify-checkpoint.php <document.json> <base64 public key>
[$script, $file, $publicKey] = $argv;

$document = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
[$format, $kid, $payload, $signature] = explode('.', $document['token']);

$b64url = fn (string $s) => base64_decode(strtr($s, '-_', '+/'), true);

$ok = $format === 'acp1' && sodium_crypto_sign_verify_detached(
    $b64url($signature),
    "acp1.{$kid}.{$payload}",
    base64_decode($publicKey, true),
);

$claims = json_decode($b64url($payload), true, flags: JSON_THROW_ON_ERROR);

echo $ok ? 'VALID' : 'INVALID', PHP_EOL;
echo json_encode($claims, JSON_PRETTY_PRINT), PHP_EOL;
```

A valid token says: the holder of that key attested that chain
`(partition, scope)` had `root_hash` at `up_to_sequence`, at `iat`.

## A chain export against it

Export the chain's rows in sequence order, recompute each hash with the
[documented format](../core-concepts/hash-format.md), and check:

1. entry 1's `prev_hash` is 64 zeros, and each later `prev_hash` is the previous
   entry's `hash`;
2. each `hash` equals `SHA-256(canonical(entry) ‖ prev_hash)`;
3. the entry at `up_to_sequence` has `hash == root_hash`.

If all three hold, the export is the chain the checkpoint attested, up to that point.
Entries after `up_to_sequence` are covered by later checkpoints only.
