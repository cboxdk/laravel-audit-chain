<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Support;

/**
 * Unpadded base64url (RFC 4648 §5), strict on the way in.
 */
class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode, or null when `$encoded` is not the one canonical unpadded base64url
     * spelling of some byte string. Rejecting non-canonical spellings means a token
     * has exactly one valid form: nothing about it is malleable.
     */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return null;
        }

        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($bytes === false || self::encode($bytes) !== $encoded) {
            return null;
        }

        return $bytes;
    }
}
