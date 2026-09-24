<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Console;

use Cbox\AuditChain\ChainVerifier;
use Cbox\AuditChain\ValueObjects\VerifiedChain;
use Illuminate\Console\Command;

/**
 * `audit-chain:verify` — verify every stored chain (or the newest `--window` entries
 * of each) and exit non-zero if any is broken or could not be verified.
 *
 * Safe to schedule and to run against production: it only reads.
 */
class VerifyCommand extends Command
{
    use ReadsListOptions;

    protected $signature = 'audit-chain:verify
        {--partition=* : Only these partitions (default: all)}
        {--scope=* : Only these scopes (default: all)}
        {--window= : Verify only the newest N entries of each chain (default: all of them)}';

    protected $description = 'Verify the hash chain and newest checkpoint of each audit chain.';

    public function handle(ChainVerifier $verifier): int
    {
        $window = $this->option('window');

        if ($window !== null && (! is_string($window) || preg_match('/\A[1-9]\d*\z/', $window) !== 1)) {
            $this->error('--window must be a positive integer.');

            return self::INVALID;
        }

        $results = $verifier->verifyAll($this->chainFilter(), $window === null ? null : (int) $window);

        if ($results === []) {
            $this->info('No audit chains to verify.');

            return self::SUCCESS;
        }

        $this->table(
            ['Partition', 'Scope', 'Head', 'Verified', 'Result'],
            array_map(fn (VerifiedChain $result): array => $this->row($result), $results),
        );

        $broken = count(array_filter($results, static fn (VerifiedChain $result): bool => ! $result->isIntact()));

        if ($broken > 0) {
            $this->error($broken.' of '.count($results).' chain(s) failed verification.');

            return self::FAILURE;
        }

        $this->info('All '.count($results).' chain(s) verified.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(VerifiedChain $result): array
    {
        $verification = $result->verification;

        return [
            $result->key->partition,
            $result->key->scope,
            (string) $result->headSequence,
            $verification === null ? '-' : (string) $verification->verifiedCount,
            match (true) {
                $result->failure !== null => 'ERROR ('.$result->failure.')',
                $verification === null => 'ERROR',
                $verification->valid => 'intact',
                default => 'BROKEN at '.$verification->brokenAtSequence.' ('.$verification->reason.')',
            },
        ];
    }
}
