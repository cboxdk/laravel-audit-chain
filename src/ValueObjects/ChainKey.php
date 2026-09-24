<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Exceptions\InvalidChainKey;

/**
 * The address of one chain: a `partition` and a `scope` within it.
 *
 * Every chain operation takes one of these, and the package attaches no meaning to
 * either half. A host decides what they are: a tenant and a user, an environment and
 * an organization, a region and a ledger. Two keys that differ in either half are two
 * independent chains, each with its own sequence, its own genesis and its own
 * checkpoints.
 *
 * Both halves are stored on every entry AND go into its hash (with the default codec),
 * so an entry cannot be moved between chains by rewriting either column.
 *
 * Neither half may be empty or contain a NUL byte. NUL is refused because it is the
 * one byte that can never appear in either, which is what lets code build an
 * unambiguous map key from the pair (see {@see self::id()}).
 */
readonly class ChainKey
{
    public function __construct(
        public string $partition,
        public string $scope,
    ) {
        self::assertPart('partition', $partition);
        self::assertPart('scope', $scope);
    }

    public static function of(string $partition, string $scope): self
    {
        return new self($partition, $scope);
    }

    public function equals(self $other): bool
    {
        return $this->partition === $other->partition && $this->scope === $other->scope;
    }

    /**
     * A collision-free string identity for the pair, for use as an array key.
     *
     * The separator is a NUL byte, which neither half can contain, so two different
     * keys can never produce the same id.
     */
    public function id(): string
    {
        return $this->partition."\0".$this->scope;
    }

    /**
     * Human-readable form for messages and command output: `partition/scope`.
     *
     * Not an identity — a `/` may legally appear in either half. Use {@see id()} for that.
     */
    public function describe(): string
    {
        return $this->partition.'/'.$this->scope;
    }

    private static function assertPart(string $name, string $value): void
    {
        if ($value === '') {
            throw InvalidChainKey::empty($name);
        }

        if (str_contains($value, "\0")) {
            throw InvalidChainKey::containsNul($name);
        }
    }
}
