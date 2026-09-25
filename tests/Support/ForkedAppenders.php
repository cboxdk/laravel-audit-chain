<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests\Support;

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Real contention: N forked processes, each appending M entries to ONE chain through
 * whatever AuditChain the container resolves (its lock strategy, its connection, its
 * database role). Only meaningful on a server engine; the caller skips otherwise.
 *
 * The caller must have committed anything the children need to see: they are separate
 * connections.
 */
final class ForkedAppenders
{
    /**
     * @return array{written: int, errors: list<string>}
     */
    public static function run(ChainKey $key, int $writers = 8, int $perWriter = 100): array
    {
        // Resolve everything the children will need while there is still one process, so
        // a lazy singleton is not built concurrently by eight of them.
        app(AuditChain::class);

        // Drop every open socket: a forked child inherits the file descriptors, and two
        // processes talking over one connection corrupts the protocol stream.
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }

        $results = sys_get_temp_dir().'/audit-chain-'.bin2hex(random_bytes(8));
        mkdir($results);

        $pids = [];

        for ($writer = 0; $writer < $writers; $writer++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('could not fork an appender');
            }

            if ($pid === 0) {
                $written = 0;
                $errors = [];

                for ($n = 0; $n < $perWriter; $n++) {
                    try {
                        app(AuditChain::class)->record($key, ChainEvent::system('concurrent.append'));
                        $written++;
                    } catch (Throwable $e) {
                        $errors[] = $e::class.': '.$e->getMessage();
                    }
                }

                file_put_contents($results.'/'.$writer.'.json', (string) json_encode(['written' => $written, 'errors' => array_slice($errors, 0, 3)]));

                // Leave without unwinding: PHPUnit's shutdown handlers would report the
                // child as a second test run and flush the parent's output buffers.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $written = 0;
        $errors = [];

        for ($writer = 0; $writer < $writers; $writer++) {
            $report = json_decode((string) file_get_contents($results.'/'.$writer.'.json'), true);

            if (! is_array($report) || ! is_int($report['written'] ?? null) || ! is_array($report['errors'] ?? null)) {
                throw new RuntimeException("appender {$writer} left no report");
            }

            $written += $report['written'];

            foreach ($report['errors'] as $error) {
                $errors[] = is_string($error) ? $error : get_debug_type($error);
            }
        }

        foreach (glob($results.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($results);

        return ['written' => $written, 'errors' => $errors];
    }
}
