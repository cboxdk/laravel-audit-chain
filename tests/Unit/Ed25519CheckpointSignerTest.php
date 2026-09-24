<?php

declare(strict_types=1);

use Cbox\AuditChain\Codec\CanonicalJson;
use Cbox\AuditChain\Exceptions\CheckpointClaimsMalformed;
use Cbox\AuditChain\Exceptions\CheckpointSignatureInvalid;
use Cbox\AuditChain\Exceptions\CheckpointSigningUnavailable;
use Cbox\AuditChain\Signing\Ed25519CheckpointSigner;
use Cbox\AuditChain\Support\Base64Url;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;

const KAT_SEED = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwc=';

/**
 * The known-answer token below was produced by calling libsodium directly on the
 * documented `acp1` layout (see docs/core-concepts/checkpoints.md) — not by this class.
 * Ed25519 is deterministic, so signing the same claims with the same seed must give
 * exactly these bytes.
 */
const KAT_TOKEN = 'acp1.test-key.eyJpYXQiOjE3ODgyNTY4MDAsInBhcnRpdGlvbiI6InRlbmFudF9hIiwicm9vdF9oYXNoIjoiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYmFiYWJhYiIsInNjb3BlIjoibGVkZ2VyIiwidHlwIjoiYXVkaXQtY2hhaW4uY2hlY2twb2ludCIsInVwX3RvX3NlcXVlbmNlIjoyfQ.LS87_-OsWTUpOA8uienpva5QeBrbh55lWXvK3-HFuECKgnwwjomzhwy1ipIC3GQScWgZRQAz5c433jjfW80MCQ';

function katClaims(): CheckpointClaims
{
    return new CheckpointClaims('ledger', 2, str_repeat('ab', 32), 1788256800, 'tenant_a');
}

function katSigner(): Ed25519CheckpointSigner
{
    return new Ed25519CheckpointSigner('test-key', KAT_SEED);
}

/**
 * Forge a token with the right key over an arbitrary payload — to prove the parser
 * refuses a signed payload that is not a checkpoint claim set.
 */
function signedPayload(string $payload): string
{
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) base64_decode(KAT_SEED)));
    $input = 'acp1.test-key.'.Base64Url::encode($payload);

    return $input.'.'.Base64Url::encode(sodium_crypto_sign_detached($input, $secret));
}

it('uses libsodium Ed25519 exactly as RFC 8032 specifies (test 1)', function (): void {
    $pair = sodium_crypto_sign_seed_keypair((string) hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60'));

    expect(bin2hex(sodium_crypto_sign_publickey($pair)))->toBe('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a')
        ->and(bin2hex(sodium_crypto_sign_detached('', sodium_crypto_sign_secretkey($pair))))
        ->toBe('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b');
});

it('signs the documented acp1 token, byte for byte', function (): void {
    expect(katSigner()->sign(katClaims()))->toBe(KAT_TOKEN);
});

it('verifies it back to the same claims', function (): void {
    $claims = katSigner()->verify(KAT_TOKEN);

    expect($claims)->toEqual(katClaims());
});

it('accepts the 64-byte libsodium secret key form too', function (): void {
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) base64_decode(KAT_SEED)));

    expect((new Ed25519CheckpointSigner('test-key', base64_encode($secret)))->sign(katClaims()))->toBe(KAT_TOKEN);
});

it('refuses a 64-byte secret key whose public half was edited', function (): void {
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) base64_decode(KAT_SEED)));
    $edited = substr($secret, 0, 32).str_repeat("\x00", 32);

    expect(fn () => (new Ed25519CheckpointSigner('test-key', base64_encode($edited)))->sign(katClaims()))
        ->toThrow(CheckpointSigningUnavailable::class, 'does not match its seed');
});

it('refuses to sign without a key, with a malformed key, or with a key id outside the alphabet', function (Ed25519CheckpointSigner $signer): void {
    expect(fn () => $signer->sign(katClaims()))->toThrow(CheckpointSigningUnavailable::class);
})->with([
    'no key' => fn () => new Ed25519CheckpointSigner,
    'not base64' => fn () => new Ed25519CheckpointSigner('k', '***'),
    'wrong length' => fn () => new Ed25519CheckpointSigner('k', base64_encode('short')),
    'dotted kid' => fn () => new Ed25519CheckpointSigner('a.b', KAT_SEED),
]);

it('refuses claims that do not name a partition', function (): void {
    expect(fn () => katSigner()->sign(new CheckpointClaims('ledger', 2, str_repeat('ab', 32), 1)))
        ->toThrow(CheckpointClaimsMalformed::class);
});

it('rejects any change to a signed token', function (string $token): void {
    expect(fn () => katSigner()->verify($token))->toThrow(CheckpointSignatureInvalid::class);
})->with([
    'flipped signature byte' => [substr(KAT_TOKEN, 0, -2).(str_ends_with(KAT_TOKEN, 'CQ') ? 'CA' : 'CQ')],
    'payload swapped' => [str_replace('.eyJpYXQiOjE3ODgyNTY4MDAs', '.eyJpYXQiOjE3ODgyNTY4MDEs', KAT_TOKEN)],
    // Relabelled to another key id: the kid is inside what was signed.
    'kid relabelled' => [str_replace('acp1.test-key.', 'acp1.other-key.', KAT_TOKEN)],
    'format tag changed' => [str_replace('acp1.', 'acp2.', KAT_TOKEN)],
    'extra segment' => [KAT_TOKEN.'.x'],
    'padded payload' => [str_replace('.LS87', '=.LS87', KAT_TOKEN)],
    'a jwt' => ['eyJhbGciOiJub25lIn0.eyJzY29wZSI6ImxlZGdlciJ9.'],
    'empty' => [''],
]);

it('rejects a token from a key it does not trust', function (): void {
    $stranger = new Ed25519CheckpointSigner('test-key', Ed25519CheckpointSigner::generateKeyPair()['secret_key']);

    expect(fn () => katSigner()->verify($stranger->sign(katClaims())))
        ->toThrow(CheckpointSignatureInvalid::class, 'signature mismatch');
});

it('verifies a retired key from the keyring, and nothing after it is removed', function (): void {
    $public = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair((string) base64_decode(KAT_SEED))));
    $rotated = Ed25519CheckpointSigner::generateKeyPair();

    $withKeyring = new Ed25519CheckpointSigner('next', $rotated['secret_key'], ['test-key' => $public]);
    $without = new Ed25519CheckpointSigner('next', $rotated['secret_key']);

    expect($withKeyring->verify(KAT_TOKEN))->toEqual(katClaims())
        ->and(fn () => $without->verify(KAT_TOKEN))->toThrow(CheckpointSignatureInvalid::class, 'unknown key id');
});

it('refuses a validly signed payload that is not a checkpoint claim set', function (string $payload): void {
    expect(fn () => katSigner()->verify(signedPayload($payload)))->toThrow(CheckpointClaimsMalformed::class);
})->with([
    'not json' => ['nope'],
    'a list' => ['[1,2]'],
    'wrong typ' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('ab', 32), 'scope' => 's', 'typ' => 'something.else', 'up_to_sequence' => 1])],
    'missing claim' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('ab', 32), 'scope' => 's', 'typ' => 'audit-chain.checkpoint'])],
    'extra claim' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('ab', 32), 'scope' => 's', 'typ' => 'audit-chain.checkpoint', 'up_to_sequence' => 1, 'x' => 1])],
    'uppercase hash' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('AB', 32), 'scope' => 's', 'typ' => 'audit-chain.checkpoint', 'up_to_sequence' => 1])],
    'sequence as string' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('ab', 32), 'scope' => 's', 'typ' => 'audit-chain.checkpoint', 'up_to_sequence' => '1'])],
    'zero sequence' => [CanonicalJson::encode(['iat' => 1, 'partition' => 'p', 'root_hash' => str_repeat('ab', 32), 'scope' => 's', 'typ' => 'audit-chain.checkpoint', 'up_to_sequence' => 0])],
    'not canonical' => ['{"typ":"audit-chain.checkpoint","iat":1,"partition":"p","root_hash":"'.str_repeat('ab', 32).'","scope":"s","up_to_sequence":1}'],
]);

it('generates key pairs that work together', function (): void {
    $pair = Ed25519CheckpointSigner::generateKeyPair();
    $signer = new Ed25519CheckpointSigner('fresh', $pair['secret_key']);
    $verifier = new Ed25519CheckpointSigner('someone-else', null, ['fresh' => $pair['public_key']]);

    expect($verifier->verify($signer->sign(katClaims())))->toEqual(katClaims())
        ->and(strlen((string) base64_decode($pair['secret_key'])))->toBe(32)
        ->and(strlen((string) base64_decode($pair['public_key'])))->toBe(32);
});
