<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use RuntimeException;

/**
 * The signer has no usable signing key. Deny-by-default: nothing is signed with a
 * made-up or empty key.
 */
class CheckpointSigningUnavailable extends RuntimeException implements AuditChainException
{
    public static function noSigningKey(): self
    {
        return new self('No checkpoint signing key is configured. Set audit-chain.signing.key_id and audit-chain.signing.secret_key (generate a pair with `php artisan audit-chain:keygen`).');
    }

    public static function invalidKey(string $reason): self
    {
        return new self('The configured checkpoint signing key is unusable: '.$reason);
    }
}
