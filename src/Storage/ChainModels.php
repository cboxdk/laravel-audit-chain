<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Storage;

use Cbox\AuditChain\Exceptions\InvalidChainModel;
use Cbox\AuditChain\Models\AuditChainCheckpoint;
use Cbox\AuditChain\Models\AuditChainEntry;
use Cbox\AuditChain\Models\ChainCheckpoint;
use Cbox\AuditChain\Models\ChainEntry;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which Eloquent models a chain reads and writes.
 *
 * Built from `audit-chain.models.*` by default. A host that keeps its own tables — or
 * adopts a chain an earlier implementation wrote — constructs one over its own model
 * classes and hands it to the chain directly, which leaves the package config (and any
 * other chain in the same app) untouched.
 *
 * Every query here drops the model's GLOBAL SCOPES and states its own predicate. A
 * chain read through an ambient scope (a tenant scope that matches nothing when no
 * tenant is set, say) silently sees an empty chain and restarts it at sequence 1 — the
 * one failure a tamper-evident log must never have.
 */
readonly class ChainModels
{
    /**
     * @param  class-string<ChainEntry>  $entry
     * @param  class-string<ChainCheckpoint>  $checkpoint
     */
    public function __construct(
        public string $entry = AuditChainEntry::class,
        public string $checkpoint = AuditChainCheckpoint::class,
    ) {}

    /**
     * From `audit-chain.models.*`, falling back to the package's own models.
     */
    public static function fromConfig(): self
    {
        $entry = config('audit-chain.models.entry');
        $checkpoint = config('audit-chain.models.checkpoint');

        if (! is_string($entry) || $entry === '') {
            $entry = AuditChainEntry::class;
        }

        if (! is_string($checkpoint) || $checkpoint === '') {
            $checkpoint = AuditChainCheckpoint::class;
        }

        if (! is_a($entry, ChainEntry::class, true)) {
            throw InvalidChainModel::mustExtend($entry, ChainEntry::class);
        }

        if (! is_a($checkpoint, ChainCheckpoint::class, true)) {
            throw InvalidChainModel::mustExtend($checkpoint, ChainCheckpoint::class);
        }

        return new self($entry, $checkpoint);
    }

    public function newEntry(): ChainEntry
    {
        return new ($this->entry);
    }

    public function newCheckpoint(): ChainCheckpoint
    {
        return new ($this->checkpoint);
    }

    /**
     * All entries of one chain, free of global scopes.
     *
     * @return Builder<ChainEntry>
     */
    public function entriesOf(ChainKey $key): Builder
    {
        $model = $this->newEntry();

        return $model->newQueryWithoutScopes()
            ->where($model->chainPartitionColumn(), $key->partition)
            ->where('scope', $key->scope);
    }

    /**
     * Entries addressed by primary key, free of global scopes.
     *
     * @return Builder<ChainEntry>
     */
    public function entries(): Builder
    {
        return $this->newEntry()->newQueryWithoutScopes();
    }

    /**
     * All checkpoints of one chain, free of global scopes.
     *
     * @return Builder<ChainCheckpoint>
     */
    public function checkpointsOf(ChainKey $key): Builder
    {
        $model = $this->newCheckpoint();

        return $model->newQueryWithoutScopes()
            ->where($model->chainPartitionColumn(), $key->partition)
            ->where('scope', $key->scope);
    }

    /**
     * The raw entry table, for sweeps that span every partition.
     */
    public function entryTable(): QueryBuilder
    {
        $model = $this->newEntry();

        return $model->getConnection()->table($model->getTable());
    }

    /**
     * The raw checkpoint table, for sweeps that span every partition.
     */
    public function checkpointTable(): QueryBuilder
    {
        $model = $this->newCheckpoint();

        return $model->getConnection()->table($model->getTable());
    }

    public function entryPartitionColumn(): string
    {
        return $this->newEntry()->chainPartitionColumn();
    }

    public function checkpointPartitionColumn(): string
    {
        return $this->newCheckpoint()->chainPartitionColumn();
    }

    /**
     * The connection the entries live on — the one an append's transaction must run on.
     */
    public function connection(): Connection
    {
        return $this->newEntry()->getConnection();
    }

    /**
     * The connection the checkpoints live on.
     */
    public function checkpointConnection(): Connection
    {
        return $this->newCheckpoint()->getConnection();
    }
}
