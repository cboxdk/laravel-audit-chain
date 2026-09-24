<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Exceptions\InvalidChainEvent;

/**
 * Who performed an audited action: a `type` from the host's own vocabulary and an
 * optional identifier.
 *
 * The package ships no fixed list of actor types because it cannot know one — a
 * multi-tenant identity platform needs "operator" and "organization member", a billing
 * engine needs "customer" and "reseller". The type is stored and hashed verbatim, so
 * hosts should use a small, stable vocabulary (a backed enum's values are ideal).
 *
 * `new ChainActor` is the system actor: an action taken by a job, a command or the
 * application itself rather than by an identifiable party.
 */
readonly class ChainActor
{
    public const SYSTEM = 'system';

    public function __construct(
        public string $type = self::SYSTEM,
        public ?string $id = null,
    ) {
        if ($type === '') {
            throw InvalidChainEvent::emptyActorType();
        }
    }

    public static function system(): self
    {
        return new self;
    }

    public static function of(string $type, ?string $id = null): self
    {
        return new self($type, $id);
    }
}
