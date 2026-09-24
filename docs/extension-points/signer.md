---
title: Checkpoint signer
weight: 42
description: Sign checkpoints with a key you already manage, or adapt an existing token format
---

# Checkpoint signer

```php
interface CheckpointSigner
{
    public function sign(CheckpointClaims $claims): string;
    public function verify(string $token): CheckpointClaims;
}
```

`CheckpointClaims` carries `scope`, `upToSequence`, `rootHash`, `issuedAt` and a
nullable `partition`.

## Contract

- `sign()` throws `CheckpointSigningUnavailable` when it has no key. Never sign with a
  placeholder.
- `verify()` throws `CheckpointSignatureInvalid` when the signature does not verify, and
  `CheckpointClaimsMalformed` when it verifies but is not a checkpoint claim set. The
  chain reports the first as `checkpoint signature failed to verify` and the second as
  `checkpoint payload does not match its signature`.
- **Pin algorithms.** Verification must accept only the algorithms you sign with. Never
  let the token choose (`alg: none`, RS/HS confusion).
- **Bind the partition** if your format can. Return `partition: null` only when adapting
  a format that never carried it; verification then trusts the row's partition and
  still checks scope, sequence and root hash.
- **Check the token's purpose.** If the key also signs other things (access tokens,
  say), put a type in what you sign and require it on verify, so no other token signed
  by that key can pass as a checkpoint.

## Example: sign with your existing JWT keys

```php
class JwtCheckpointSigner implements CheckpointSigner
{
    public function __construct(private readonly MyJwtKeys $keys) {}

    public function sign(CheckpointClaims $claims): string
    {
        return $this->keys->sign([
            'typ' => 'acme.audit.checkpoint',
            'partition' => $claims->partition,
            'scope' => $claims->scope,
            'up_to_sequence' => $claims->upToSequence,
            'root_hash' => $claims->rootHash,
            'iat' => $claims->issuedAt,
        ]);
    }

    public function verify(string $token): CheckpointClaims
    {
        try {
            $claims = $this->keys->verify($token, allowed: ['ES256']);
        } catch (Throwable $e) {
            throw CheckpointSignatureInvalid::because($e->getMessage(), $e);
        }

        if (($claims['typ'] ?? null) !== 'acme.audit.checkpoint'
            || ! is_string($claims['scope'] ?? null) /* … every field … */) {
            throw CheckpointClaimsMalformed::because('not a checkpoint');
        }

        return new CheckpointClaims($claims['scope'], $claims['up_to_sequence'], $claims['root_hash'], $claims['iat'], $claims['partition']);
    }
}
```

Use a vetted JWT/JWS library for the token itself. Do not parse or verify JWTs by hand.
