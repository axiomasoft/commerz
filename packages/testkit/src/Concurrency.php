<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Runs the same work in N forked processes that start together, each on its own database connection, to prove
 * locking invariants on PostgreSQL. Data must be committed: transactional test isolation (RefreshDatabase,
 * DatabaseTransactions) cannot be used with it, because the parent's connections are closed before forking.
 *
 * ```php
 * $results = Concurrency::run(8, fn (int $worker) => $reservations->reserve(...));
 * ```
 */
final class Concurrency
{
    /**
     * @template T
     *
     * @param  Closure(int): T  $work  receives the worker index 0..N-1; its result must be serializable
     * @return list<T> results in worker order
     *
     * @throws RuntimeException when a worker throws, crashes or misses the start barrier
     */
    public static function run(int $workers, Closure $work, int $timeoutSeconds = 30): array
    {
        if ($workers < 1) {
            throw new RuntimeException('Concurrency::run() needs at least one worker.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('Concurrency::run() needs the pcntl and posix extensions.');
        }

        $database = Container::getInstance()->make(DatabaseManager::class);
        $directory = sys_get_temp_dir().'/commerz-concurrency-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        // A forked child must never share (and on exit close) the parent's socket.
        self::disconnect($database);

        $pids = [];

        try {
            for ($worker = 0; $worker < $workers; $worker++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('pcntl_fork() failed.');
                }

                if ($pid === 0) {
                    self::child($database, $directory, $worker, $work, $timeoutSeconds);
                }

                $pids[$worker] = $pid;
            }

            self::waitFor(static fn (): bool => count(self::files("{$directory}/ready.*")) === $workers, $timeoutSeconds, 'workers did not get ready');
            touch("{$directory}/go");

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $pids = [];

            return self::collect($directory, $workers);
        } finally {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            array_map(unlink(...), self::files("{$directory}/*"));
            rmdir($directory);
        }
    }

    /** @param  Closure(int): mixed  $work */
    private static function child(DatabaseManager $database, string $directory, int $worker, Closure $work, int $timeoutSeconds): never
    {
        try {
            $database->connection()->getPdo();
            touch("{$directory}/ready.{$worker}");
            self::waitFor(static fn (): bool => file_exists("{$directory}/go"), $timeoutSeconds, 'start signal did not come');

            $outcome = ['ok' => true, 'value' => $work($worker)];
        } catch (Throwable $e) {
            $outcome = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()];
        }

        try {
            file_put_contents("{$directory}/result.{$worker}", serialize($outcome));
        } catch (Throwable $e) {
            file_put_contents("{$directory}/result.{$worker}", serialize(['ok' => false, 'error' => 'unserializable result: '.$e->getMessage()]));
        }

        self::disconnect($database);

        // Skip the test runner's shutdown handlers and destructors inherited from the parent.
        posix_kill(posix_getpid(), SIGKILL);

        exit(1);
    }

    /** @return list<mixed> */
    private static function collect(string $directory, int $workers): array
    {
        $results = [];
        $failures = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $file = "{$directory}/result.{$worker}";
            $outcome = is_file($file) ? unserialize((string) file_get_contents($file)) : null;

            if (! is_array($outcome)) {
                $failures[] = "worker {$worker}: exited without a result";
                $results[] = null;

                continue;
            }

            if ($outcome['ok'] !== true) {
                $error = $outcome['error'] ?? 'unknown error';
                $failures[] = "worker {$worker}: ".(is_string($error) ? $error : 'unknown error');
            }

            $results[] = $outcome['value'] ?? null;
        }

        if ($failures !== []) {
            throw new RuntimeException("Concurrency::run() workers failed:\n".implode("\n", $failures));
        }

        return $results;
    }

    /** @return list<string> */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private static function disconnect(DatabaseManager $database): void
    {
        foreach (array_keys($database->getConnections()) as $name) {
            $database->disconnect($name);
        }
    }

    /** @param  Closure(): bool  $condition */
    private static function waitFor(Closure $condition, int $timeoutSeconds, string $failure): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Concurrency::run(): {$failure} within {$timeoutSeconds}s.");
            }

            usleep(500);
        }
    }
}
