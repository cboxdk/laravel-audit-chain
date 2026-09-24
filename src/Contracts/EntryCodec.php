<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\ValueObjects\ChainEvent;

/**
 * Turns an entry into the exact bytes that are hashed.
 *
 * The chain's hash is `SHA-256(canonicalize(entry) ‖ prev_hash)`. The codec owns the
 * first half, and it must be DETERMINISTIC: the same stored row must produce the same
 * bytes at write time and at every later verification, on every engine and PHP build
 * the host runs. It is called on the model both right after it is filled (before
 * insert) and after it is read back, so it must read values through the model's
 * casts and never from anything that changes between the two.
 *
 * Changing a codec's output for existing rows breaks every existing chain. A new
 * canonical form is a new codec with a new {@see version()}, adopted by re-chaining
 * (or by a codec that dispatches on a stored version column) — never an edit.
 *
 * Binding your own codec is also how a chain written by another implementation is
 * adopted without rewriting it: reproduce its canonical form byte for byte.
 */
interface EntryCodec
{
    /**
     * A stable identifier for this canonical form, e.g. `audit-chain/v1`.
     */
    public function version(): string;

    /**
     * Host columns (beyond the ones the chain maintains) that this codec hashes.
     *
     * An append refuses any {@see ChainEvent::$columns}
     * entry not listed here: a column that is stored but not hashed could be rewritten
     * without the chain noticing.
     *
     * @return list<string>
     */
    public function extraColumns(): array;

    /**
     * The canonical bytes of one entry: everything that is hashed, excluding
     * `hash` and `prev_hash` themselves.
     */
    public function canonicalize(ChainEntry $entry): string;
}
