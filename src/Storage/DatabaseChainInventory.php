<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Storage;

use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\ValueObjects\ChainFilter;
use Cbox\AuditChain\ValueObjects\ChainHead;
use Cbox\AuditChain\ValueObjects\ChainKey;

/**
 * Lists stored chains straight from the entry table.
 *
 * Partition-SPANNING by design, so it reads the tables through the query builder
 * rather than the models: a model with a tenant or partition global scope would
 * otherwise show a sweep only the chains of whatever context it happened to run in.
 * Reading the entry table (not a registry of tenants) is exactly the set of chains
 * that exist — including ones whose partition has no row anywhere else.
 */
class DatabaseChainInventory implements ChainInventory
{
    public function __construct(
        private readonly ChainModels $models,
    ) {}

    public function heads(ChainFilter $filter = new ChainFilter): array
    {
        $column = $this->models->entryPartitionColumn();

        $query = $this->models->entryTable()
            ->select($column, 'scope')
            ->selectRaw('max(sequence) as head_sequence')
            ->groupBy($column, 'scope')
            ->orderBy($column)
            ->orderBy('scope');

        if ($filter->partitions !== []) {
            $query->whereIn($column, $filter->partitions);
        }

        if ($filter->scopes !== []) {
            $query->whereIn('scope', $filter->scopes);
        }

        $attested = $this->attestedSequences();
        $heads = [];

        foreach ($query->get() as $row) {
            $partition = $row->{$column} ?? null;
            $scope = $row->scope ?? null;
            $head = $row->head_sequence ?? null;

            if (! is_string($partition) || $partition === '' || ! is_string($scope) || $scope === '' || ! is_numeric($head)) {
                continue;
            }

            $key = new ChainKey($partition, $scope);

            $heads[] = new ChainHead($key, (int) $head, $attested[$key->id()] ?? null);
        }

        return $heads;
    }

    /**
     * The highest sequence already attested for each chain, keyed by ChainKey::id().
     *
     * @return array<string, int>
     */
    private function attestedSequences(): array
    {
        $column = $this->models->checkpointPartitionColumn();

        $rows = $this->models->checkpointTable()
            ->select($column, 'scope')
            ->selectRaw('max(up_to_sequence) as attested_sequence')
            ->groupBy($column, 'scope')
            ->get();

        $attested = [];

        foreach ($rows as $row) {
            $partition = $row->{$column} ?? null;
            $scope = $row->scope ?? null;
            $sequence = $row->attested_sequence ?? null;

            if (! is_string($partition) || $partition === '' || ! is_string($scope) || $scope === '' || ! is_numeric($sequence)) {
                continue;
            }

            $attested[(new ChainKey($partition, $scope))->id()] = (int) $sequence;
        }

        return $attested;
    }
}
