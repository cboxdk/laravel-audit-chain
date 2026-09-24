<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Signing;

use Cbox\AuditChain\Codec\CanonicalJson;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Exceptions\CheckpointClaimsMalformed;
use Cbox\AuditChain\Exceptions\CheckpointSignatureInvalid;
use Cbox\AuditChain\Exceptions\CheckpointSigningUnavailable;
use Cbox\AuditChain\Support\Base64Url;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;
use JsonException;
use SensitiveParameter;
use SodiumException;

/**
 * The package's default {@see CheckpointSigner}: Ed25519 (RFC 8032) through ext-sodium,
 * over canonical JSON.
 *
 * ## Token format — `acp1`
 *
 *     acp1.<kid>.<base64url(payload)>.<base64url(signature)>
 *
 * - `payload` is the {@see CanonicalJson} encoding of
 *   `{"iat", "partition", "root_hash", "scope", "typ": "audit-chain.checkpoint", "up_to_sequence"}`.
 * - `signature` is the 64-byte Ed25519 detached signature over the ASCII bytes
 *   `acp1.<kid>.<base64url(payload)>` — so the format tag, the key id and the payload
 *   are all covered, and a token cannot be re-labelled to another key.
 * - `kid` is 1-64 characters of `[A-Za-z0-9_-]`.
 *
 * This is deliberately NOT JWS: there is no header to negotiate an algorithm, so there
 * is nothing to confuse. The algorithm is Ed25519 because this class only speaks
 * Ed25519; the key is chosen by `kid` from a configured keyring; nothing in the token
 * can change either. Signing and verification are libsodium primitives
 * (`sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached`) — no
 * cryptography is implemented here.
 *
 * ## Keys
 *
 * The signing key is a 32-byte Ed25519 seed (or the 64-byte libsodium secret key),
 * base64-encoded. Its public key is derived, so the active key always verifies its own
 * tokens. Retired keys stay trusted for verification by listing their PUBLIC keys in
 * the keyring — rotate by adding a new active key and moving the old public key there,
 * never by deleting it, or every checkpoint it signed stops verifying.
 *
 * Keys are decoded when used, not when the signer is built: an unset or malformed key
 * fails the checkpoint that needs it, not every request that resolves the chain.
 */
class Ed25519CheckpointSigner implements CheckpointSigner
{
    public const FORMAT = 'acp1';

    public const TYPE = 'audit-chain.checkpoint';

    private const KID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * @param  string|null  $keyId  the active key's id
     * @param  string|null  $secretKey  base64 32-byte seed or 64-byte secret key
     * @param  array<string, string>  $publicKeys  kid => base64 32-byte public key, for retired keys
     */
    public function __construct(
        private readonly ?string $keyId = null,
        #[SensitiveParameter]
        private readonly ?string $secretKey = null,
        private readonly array $publicKeys = [],
    ) {}

    public static function fromConfig(): self
    {
        $keyId = config('audit-chain.signing.key_id');
        $secretKey = config('audit-chain.signing.secret_key');
        $configured = config('audit-chain.signing.public_keys', []);

        $publicKeys = [];

        foreach (is_array($configured) ? $configured : [] as $kid => $publicKey) {
            if (is_string($kid) && is_string($publicKey) && $publicKey !== '') {
                $publicKeys[$kid] = $publicKey;
            }
        }

        return new self(
            is_string($keyId) && $keyId !== '' ? $keyId : null,
            is_string($secretKey) && $secretKey !== '' ? $secretKey : null,
            $publicKeys,
        );
    }

    /**
     * A fresh key pair, base64-encoded: the seed to keep secret and the public key to
     * publish (and to keep in the keyring after rotation).
     *
     * @return array{secret_key: string, public_key: string}
     */
    public static function generateKeyPair(): array
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);

        $keys = [
            'secret_key' => base64_encode($seed),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];

        sodium_memzero($seed);
        sodium_memzero($pair);

        return $keys;
    }

    public function sign(CheckpointClaims $claims): string
    {
        if ($this->keyId === null || $this->secretKey === null) {
            throw CheckpointSigningUnavailable::noSigningKey();
        }

        if (preg_match(self::KID_PATTERN, $this->keyId) !== 1) {
            throw CheckpointSigningUnavailable::invalidKey('the key id must be 1-64 characters of [A-Za-z0-9_-]');
        }

        if ($claims->partition === null) {
            throw CheckpointClaimsMalformed::because('this signer always binds the partition, and none was given');
        }

        $payload = CanonicalJson::encode([
            'iat' => $claims->issuedAt,
            'partition' => $claims->partition,
            'root_hash' => $claims->rootHash,
            'scope' => $claims->scope,
            'typ' => self::TYPE,
            'up_to_sequence' => $claims->upToSequence,
        ]);

        $signingInput = self::FORMAT.'.'.$this->keyId.'.'.Base64Url::encode($payload);
        $secret = $this->secretKeyBytes();

        try {
            $signature = sodium_crypto_sign_detached($signingInput, $secret);
        } catch (SodiumException $failure) {
            throw CheckpointSigningUnavailable::invalidKey($failure->getMessage());
        } finally {
            sodium_memzero($secret);
        }

        return $signingInput.'.'.Base64Url::encode($signature);
    }

    public function verify(string $token): CheckpointClaims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4 || $parts[0] !== self::FORMAT) {
            throw CheckpointSignatureInvalid::because('not an '.self::FORMAT.' token');
        }

        [, $kid, $encodedPayload, $encodedSignature] = $parts;

        if (preg_match(self::KID_PATTERN, $kid) !== 1) {
            throw CheckpointSignatureInvalid::because('malformed key id');
        }

        $publicKey = $this->publicKeyFor($kid);

        if ($publicKey === null) {
            throw CheckpointSignatureInvalid::because("unknown key id [{$kid}]");
        }

        $payload = Base64Url::decode($encodedPayload);
        $signature = Base64Url::decode($encodedSignature);

        if ($payload === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw CheckpointSignatureInvalid::because('malformed token encoding');
        }

        try {
            $verified = sodium_crypto_sign_verify_detached($signature, self::FORMAT.'.'.$kid.'.'.$encodedPayload, $publicKey);
        } catch (SodiumException $failure) {
            throw CheckpointSignatureInvalid::because($failure->getMessage(), $failure);
        }

        if (! $verified) {
            throw CheckpointSignatureInvalid::because('signature mismatch');
        }

        return $this->claimsFrom($payload);
    }

    /**
     * Parse a VERIFIED payload into claims, refusing anything that is not exactly a
     * checkpoint claim set in canonical form.
     */
    private function claimsFrom(string $payload): CheckpointClaims
    {
        try {
            $decoded = json_decode($payload, true, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw CheckpointClaimsMalformed::because('payload is not JSON');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw CheckpointClaimsMalformed::because('payload is not an object');
        }

        $expected = ['iat', 'partition', 'root_hash', 'scope', 'typ', 'up_to_sequence'];
        $keys = array_map(strval(...), array_keys($decoded));
        sort($keys);

        if ($keys !== $expected) {
            throw CheckpointClaimsMalformed::because('payload must carry exactly: '.implode(', ', $expected));
        }

        if ($decoded['typ'] !== self::TYPE) {
            throw CheckpointClaimsMalformed::because('not a checkpoint token (typ)');
        }

        $partition = $decoded['partition'];
        $scope = $decoded['scope'];
        $rootHash = $decoded['root_hash'];
        $upTo = $decoded['up_to_sequence'];
        $issuedAt = $decoded['iat'];

        if (! is_string($partition) || $partition === '' || ! is_string($scope) || $scope === '') {
            throw CheckpointClaimsMalformed::because('partition and scope must be non-empty strings');
        }

        if (! is_string($rootHash) || preg_match('/\A[0-9a-f]{64}\z/', $rootHash) !== 1) {
            throw CheckpointClaimsMalformed::because('root_hash must be 64 lowercase hex characters');
        }

        if (! is_int($upTo) || $upTo < 1 || ! is_int($issuedAt)) {
            throw CheckpointClaimsMalformed::because('up_to_sequence and iat must be integers');
        }

        if (CanonicalJson::encode($decoded) !== $payload) {
            throw CheckpointClaimsMalformed::because('payload is not in canonical form');
        }

        return new CheckpointClaims($scope, $upTo, $rootHash, $issuedAt, $partition);
    }

    /**
     * The 64-byte libsodium secret key for the active key.
     *
     * @return non-empty-string
     */
    private function secretKeyBytes(): string
    {
        $raw = base64_decode((string) $this->secretKey, true);

        if ($raw === false || $raw === '') {
            throw CheckpointSigningUnavailable::invalidKey('the secret key is not valid base64');
        }

        try {
            $seed = match (strlen($raw)) {
                SODIUM_CRYPTO_SIGN_SEEDBYTES => $raw,
                SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => substr($raw, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES),
                default => throw CheckpointSigningUnavailable::invalidKey('expected a 32-byte seed or a 64-byte secret key'),
            };

            $pair = sodium_crypto_sign_seed_keypair($seed);
            $secret = sodium_crypto_sign_secretkey($pair);

            // A 64-byte libsodium secret key is seed ‖ public key. Refuse one whose
            // halves disagree rather than sign with a key whose public half was edited.
            if (strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
                && ! hash_equals(sodium_crypto_sign_publickey($pair), substr($raw, SODIUM_CRYPTO_SIGN_SEEDBYTES))) {
                throw CheckpointSigningUnavailable::invalidKey('the secret key\'s public half does not match its seed');
            }

            sodium_memzero($pair);
            sodium_memzero($seed);

            return $secret;
        } catch (SodiumException $failure) {
            throw CheckpointSigningUnavailable::invalidKey($failure->getMessage());
        } finally {
            sodium_memzero($raw);
        }
    }

    /**
     * @return non-empty-string|null
     */
    private function publicKeyFor(string $kid): ?string
    {
        if ($kid === $this->keyId && $this->secretKey !== null) {
            try {
                $secret = $this->secretKeyBytes();
            } catch (CheckpointSigningUnavailable) {
                return null;
            }

            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
            sodium_memzero($secret);

            return $public;
        }

        $encoded = $this->publicKeys[$kid] ?? null;

        if ($encoded === null) {
            return null;
        }

        $raw = base64_decode($encoded, true);

        return $raw !== false && $raw !== '' && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }
}
