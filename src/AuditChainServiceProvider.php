<?php

declare(strict_types=1);

namespace Cbox\AuditChain;

use Cbox\AuditChain\Anchoring\FilesystemCheckpointAnchor;
use Cbox\AuditChain\Anchoring\NullCheckpointAnchor;
use Cbox\AuditChain\Codec\V1EntryCodec;
use Cbox\AuditChain\Console\CheckpointCommand;
use Cbox\AuditChain\Console\KeygenCommand;
use Cbox\AuditChain\Console\VerifyCommand;
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\Contracts\ChainLock;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Exceptions\UnsupportedChainLock;
use Cbox\AuditChain\Locking\AnchorRowChainLock;
use Cbox\AuditChain\Locking\AutoChainLock;
use Cbox\AuditChain\Locking\PostgresAdvisoryChainLock;
use Cbox\AuditChain\Signing\Ed25519CheckpointSigner;
use Cbox\AuditChain\Storage\ChainModels;
use Cbox\AuditChain\Storage\DatabaseChainInventory;
use Cbox\AuditChain\Support\PassthroughChainContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds every contract to its default — each with `singletonIf`, so a host (or a
 * framework built on this package) that binds its own first keeps it, and one that
 * binds later replaces it. Registration order between the two never matters.
 */
class AuditChainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-chain.php', 'audit-chain');

        $this->app->singletonIf(ChainModels::class, static fn (): ChainModels => ChainModels::fromConfig());
        $this->app->singletonIf(EntryCodec::class, static fn (): EntryCodec => V1EntryCodec::fromConfig());
        $this->app->singletonIf(CheckpointSigner::class, static fn (): CheckpointSigner => Ed25519CheckpointSigner::fromConfig());
        $this->app->singletonIf(CheckpointAnchor::class, static fn (Container $app): CheckpointAnchor => self::anchorFromConfig($app));
        $this->app->singletonIf(ChainContext::class, PassthroughChainContext::class);
        $this->app->singletonIf(ChainInventory::class, DatabaseChainInventory::class);
        $this->app->singletonIf(ChainLock::class, static fn (): ChainLock => self::lockFromConfig());
        $this->app->singletonIf(AuditChain::class, DatabaseAuditChain::class);
        $this->app->singletonIf(Checkpointer::class);
        $this->app->singletonIf(ChainVerifier::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit-chain.php' => config_path('audit-chain.php'),
            ], 'audit-chain-config');

            // Published, never auto-loaded: a host that keeps the chain in tables of
            // its own must not get a second, empty pair it never asked for.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'audit-chain-migrations');

            $this->commands([CheckpointCommand::class, VerifyCommand::class, KeygenCommand::class]);
        }

        $this->scheduleIfEnabled();
    }

    /**
     * The checkpoint pass is OFF by default — the flag is a safety catch, not a
     * preference: the first signed checkpoint forecloses any later re-chain. See
     * {@see Checkpointer}. The verify pass only reads, but is opt-in too, so
     * installing the package never adds scheduled work on its own.
     *
     * A malformed time falls back rather than leaving a pass unscheduled — a typo in
     * a time string must not silently switch a tamper control off.
     */
    private function scheduleIfEnabled(): void
    {
        $checkpoint = config('audit-chain.checkpoint.schedule', false) === true;
        $verify = config('audit-chain.verify.schedule', false) === true;

        if (! $checkpoint && ! $verify) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($checkpoint, $verify): void {
            if ($checkpoint) {
                $schedule->command(CheckpointCommand::class)
                    ->dailyAt(self::time('audit-chain.checkpoint.time', '02:40'))
                    ->name('audit-chain:checkpoint')
                    ->withoutOverlapping();
            }

            if ($verify) {
                $window = config('audit-chain.verify.window', 1000);
                $window = is_numeric($window) && (int) $window > 0 ? (int) $window : 1000;

                $schedule->command(VerifyCommand::class, ['--window' => (string) $window])
                    ->dailyAt(self::time('audit-chain.verify.time', '03:10'))
                    ->name('audit-chain:verify')
                    ->withoutOverlapping();
            }
        });
    }

    private static function time(string $key, string $default): string
    {
        $time = config($key, $default);

        return is_string($time) && preg_match('/\A([01]?\d|2[0-3]):[0-5]\d\z/', $time) === 1 ? $time : $default;
    }

    /**
     * The anchor-row lock unless configured otherwise: it is the measured default, and
     * the one every chain written so far was written under.
     */
    private static function lockFromConfig(): ChainLock
    {
        $driver = config('audit-chain.lock.driver', 'anchor');
        $driver = is_string($driver) && $driver !== '' ? $driver : 'anchor';

        return match ($driver) {
            'anchor' => new AnchorRowChainLock,
            'advisory' => new PostgresAdvisoryChainLock,
            'auto' => new AutoChainLock,
            default => throw UnsupportedChainLock::unknown($driver),
        };
    }

    private static function anchorFromConfig(Container $app): CheckpointAnchor
    {
        $driver = config('audit-chain.anchor.driver', 'null');

        if ($driver === null || $driver === 'null' || $driver === '') {
            return new NullCheckpointAnchor;
        }

        if ($driver === 'filesystem') {
            $disk = config('audit-chain.anchor.disk');
            $prefix = config('audit-chain.anchor.prefix', 'audit-chain/checkpoints');

            $filesystems = $app->make(FilesystemFactory::class);

            return new FilesystemCheckpointAnchor(
                $filesystems->disk(is_string($disk) && $disk !== '' ? $disk : null),
                is_string($prefix) ? $prefix : 'audit-chain/checkpoints',
            );
        }

        throw new InvalidArgumentException('Unknown audit-chain.anchor.driver ['.(is_scalar($driver) ? (string) $driver : get_debug_type($driver)).']: use "null" or "filesystem", or bind your own CheckpointAnchor.');
    }
}
