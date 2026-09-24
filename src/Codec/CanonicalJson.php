<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Codec;

/**
 * The one JSON form the package hashes and signs.
 *
 * - Maps are sorted by key, compared as byte strings (`SORT_STRING`), at every depth.
 * - Lists (`array_is_list`) keep their order — reordering a list changes its meaning.
 * - Slashes and non-ASCII characters are written as-is, not escaped, so the same text
 *   has exactly one encoding.
 *
 * Deterministic for JSON-native values. Floats render per PHP's shortest round-trip
 * form, which is stable for a given value; keep them out of anything you hash if you
 * can.
 */
class CanonicalJson
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function encode(array $value): string
    {
        return json_encode(self::normalize($value), self::FLAGS);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function normalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }
}
