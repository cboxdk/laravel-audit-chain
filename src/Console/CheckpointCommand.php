<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Console;

use Cbox\AuditChain\AuditChainServiceProvider;
use Cbox\AuditChain\Checkpointer;
use Cbox\AuditChain\ValueObjects\CheckpointOutcome;
use Illuminate\Console\Command;

/**
 * `audit-chain:checkpoint` — sign a checkpoint over every chain that has advanced
 * since its last one.
 *
 * Scheduled by {@see AuditChainServiceProvider} ONLY when
 * `audit-chain.checkpoint.schedule` is true, and that flag defaults to false: read
 * {@see Checkpointer} before turning it on — the first signature is a one-way door.
 *
 * `--dry-run` reports which chains would be signed and at which sequence, which is the
 * right first move on a trail that has never been checkpointed.
 */
class CheckpointCommand extends Command
{
    use ReadsListOptions;

    protected $signature = 'audit-chain:checkpoint
        {--partition=* : Only these partitions (default: all)}
        {--scope=* : Only these scopes (default: all)}
        {--force : Sign even when the chain has not advanced since its last checkpoint}
        {--dry-run : Report what would be signed, sign nothing}';

    protected $description = 'Sign a checkpoint over each audit chain, so tail deletion becomes detectable.';

    public function handle(Checkpointer $checkpointer): int
    {
        $dryRun = $this->option('dry-run') === true;

        if ($dryRun) {
            $this->comment('Dry run — nothing will be signed.');
        }

        $outcomes = $checkpointer->checkpointAll(
            dryRun: $dryRun,
            force: $this->option('force') === true,
            filter: $this->chainFilter(),
        );

        if ($outcomes === []) {
            $this->info('No audit chains to checkpoint.');

            return self::SUCCESS;
        }

        $signed = 0;
        $failed = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome->hasFailed()) {
                $failed++;
            } elseif (! $outcome->wasSkipped()) {
                $signed++;
            }
        }

        $this->table(
            ['Partition', 'Scope', 'Head', 'Last checkpoint', $dryRun ? 'Would sign' : 'Signed'],
            array_map(fn (CheckpointOutcome $outcome): array => $this->row($outcome), $outcomes),
        );

        $this->info(($dryRun ? 'Would sign ' : 'Signed ').$signed.' checkpoint(s) over '.count($outcomes).' chain(s).');

        // Stated every run: a checkpoint is not just another maintenance artefact, it
        // is the thing a later re-chain cannot contradict.
        $this->line('<fg=gray>A signed checkpoint is permanent evidence. Re-chaining after this point (a codec change, hashing ciphertext instead of plaintext) would make every retained checkpoint report tampering.</>');

        if ($failed > 0) {
            // Non-zero, so a scheduler or a probe sees it. The chains that COULD be
            // signed were signed.
            $this->error($failed.' chain(s) could not be checkpointed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(CheckpointOutcome $outcome): array
    {
        return [
            $outcome->key->partition,
            $outcome->key->scope,
            (string) $outcome->headSequence,
            $outcome->checkpointedSequence === null ? 'never' : (string) $outcome->checkpointedSequence,
            match (true) {
                $outcome->hasFailed() => 'FAILED ('.$outcome->failureReason.')',
                $outcome->wasSkipped() => 'skipped ('.$outcome->skippedReason.')',
                default => 'yes, at '.$outcome->headSequence,
            },
        ];
    }
}
