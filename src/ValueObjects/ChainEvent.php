<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Exceptions\InvalidChainEvent;

/**
 * One event to append to a chain. Which chain is not part of the event: it is the
 * {@see ChainKey} passed alongside it, so the same event shape serves every chain.
 *
 * `context` is free-form structured detail. It is stored as JSON and hashed in a
 * canonical, recursively key-sorted form, so key ORDER never matters but list order
 * does. Keep it to JSON-native values (strings, integers, booleans, null, nested
 * arrays): a float is hashed in whatever form PHP's JSON encoder renders it after a
 * database round trip, which is stable on one PHP build but is a needless thing to
 * depend on.
 *
 * `columns` is for a host whose entry table carries columns of its own (a tenant id,
 * a request id). They are written to the row verbatim, and the append refuses any
 * column the bound {@see EntryCodec} does not declare in `extraColumns()` — a column
 * that is stored but not hashed could be rewritten without the chain noticing, so it
 * is never written silently.
 */
readonly class ChainEvent
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string|int|bool|null>  $columns
     */
    public function __construct(
        public string $action,
        public ChainActor $actor = new ChainActor,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public array $context = [],
        public ?string $ip = null,
        public array $columns = [],
    ) {
        if ($action === '') {
            throw InvalidChainEvent::emptyAction();
        }
    }

    /**
     * An action taken by the application itself.
     *
     * @param  array<string, mixed>  $context
     */
    public static function system(string $action, array $context = []): self
    {
        return new self(action: $action, context: $context);
    }

    /**
     * An action taken by an identifiable party.
     *
     * @param  array<string, mixed>  $context
     */
    public static function by(ChainActor $actor, string $action, array $context = []): self
    {
        return new self(action: $action, actor: $actor, context: $context);
    }

    /**
     * The same event, aimed at a target.
     */
    public function on(string $targetType, ?string $targetId = null): self
    {
        return new self($this->action, $this->actor, $targetType, $targetId, $this->context, $this->ip, $this->columns);
    }

    /**
     * The same event, recorded as coming from an address.
     */
    public function from(?string $ip): self
    {
        return new self($this->action, $this->actor, $this->targetType, $this->targetId, $this->context, $ip, $this->columns);
    }

    /**
     * The same event, with host columns to write to the entry row.
     *
     * @param  array<string, string|int|bool|null>  $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->action, $this->actor, $this->targetType, $this->targetId, $this->context, $this->ip, array_merge($this->columns, $columns));
    }
}
