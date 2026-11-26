<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Api;
use Packman\Testkit\Concurrency\Process\Concurrency as PackmanConcurrency;
use PDOException;
use PHPUnit\Framework\Assert;
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
#[Api(since: '0.1')]
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

        $container = Container::getInstance();
        $database = $container->bound(DatabaseManager::class)
            ? $container->make(DatabaseManager::class)
            : null;

        // A forked child must never share (and on exit close) the parent's socket.
        if ($database !== null) {
            self::disconnect($database);
        }

        $tasks = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $tasks[] = static function () use ($database, $timeoutSeconds, $work, $worker): mixed {
                if ($timeoutSeconds > 0 && function_exists('pcntl_alarm')) {
                    pcntl_alarm($timeoutSeconds);
                }

                try {
                    $database?->connection()->getPdo();

                    return $work($worker);
                } finally {
                    if ($timeoutSeconds > 0 && function_exists('pcntl_alarm')) {
                        pcntl_alarm(0);
                    }

                    if ($database !== null) {
                        self::disconnect($database);
                    }
                }
            };
        }

        $outcomes = PackmanConcurrency::run($tasks);
        $results = [];
        $failures = [];

        foreach ($outcomes as $outcome) {
            if (! $outcome->succeeded) {
                $detail = $outcome->errorClass !== null && $outcome->errorMessage !== null
                    ? "{$outcome->errorClass}: {$outcome->errorMessage}"
                    : ($outcome->errorMessage ?? $outcome->errorClass ?? 'unknown error');
                $failures[] = "worker {$outcome->index}: {$detail}";
            }

            /** @var T $value */
            $value = $outcome->value;
            $results[] = $value;
        }

        if ($failures !== []) {
            throw new RuntimeException("Concurrency::run() workers failed:\n".implode("\n", $failures));
        }

        return $results;
    }

    /**
     * Runs N forked processes racing on the same operation, mapping expected domain exceptions to outcome labels and
     * surfacing any unexpected exception or PostgreSQL error (e.g. 40P01 deadlock, 55P03 lock timeout) as `error:...`.
     *
     * On PostgreSQL connections, lowers `deadlock_timeout` per worker session so lock cycles surface promptly as 40P01.
     *
     * @param  Closure(int): string  $work  receives worker index 0..N-1 and returns an outcome label
     * @param  array<class-string<Throwable>, string|(Closure(Throwable): string)>  $expected  expected exception class => outcome label or mapper
     * @return list<string> outcome labels in worker order
     */
    public static function race(
        int $workers,
        Closure $work,
        array $expected = [],
        int $timeoutSeconds = 60,
        string $deadlockTimeout = '100ms',
    ): array {
        if (preg_match('/\A[1-9][0-9]*ms\z/', $deadlockTimeout) !== 1) {
            throw new RuntimeException("Invalid PostgreSQL deadlock_timeout '{$deadlockTimeout}'; expected '<positive-int>ms'.");
        }

        return self::run($workers, static function (int $worker) use ($work, $expected, $deadlockTimeout): string {
            $database = self::databaseManager();

            if ($database !== null && $database->connection()->getDriverName() === 'pgsql') {
                $database->connection()->statement("set deadlock_timeout = '{$deadlockTimeout}'");
            }

            try {
                return $work($worker);
            } catch (Throwable $e) {
                foreach ($expected as $class => $outcome) {
                    if ($e instanceof $class) {
                        return $outcome instanceof Closure ? $outcome($e) : $outcome;
                    }
                }

                $sqlState = self::sqlState($e);

                return 'error:'.($sqlState ?? $e::class.':'.$e->getMessage());
            }
        }, $timeoutSeconds);
    }

    /**
     * Counts outcome labels from a {@see self::race()} run, sorted alphabetically by label.
     *
     * @param  list<string>  $outcomes
     * @return array<string, int>
     */
    public static function tally(array $outcomes): array
    {
        $counts = array_count_values($outcomes);
        ksort($counts);

        return $counts;
    }

    /**
     * Extracts unexpected worker errors (`error:...`) from {@see self::race()} outcomes.
     *
     * @param  list<string>  $outcomes
     * @return list<string>
     */
    public static function unexpectedErrors(array $outcomes): array
    {
        return array_values(array_filter(
            $outcomes,
            static fn (string $outcome): bool => str_starts_with($outcome, 'error:'),
        ));
    }

    /**
     * Asserts a multi-process race settled with zero unexpected errors, zero new PostgreSQL deadlocks (when a baseline
     * count from {@see self::pgDeadlocks()} is provided), and exact expected outcome counts.
     *
     * @param  list<string>  $outcomes
     * @param  array<string, int>  $expectedCounts
     */
    public static function assertRaceSettled(
        array $outcomes,
        array $expectedCounts,
        ?int $deadlocksBefore = null,
        ?string $connection = null,
    ): void {
        $unexpected = self::unexpectedErrors($outcomes);
        Assert::assertSame([], $unexpected, 'Unexpected worker errors during concurrent race: '.implode('; ', $unexpected));

        if ($deadlocksBefore !== null) {
            Assert::assertSame(
                0,
                self::deadlocksSince($deadlocksBefore, $connection),
                'PostgreSQL recorded one or more deadlocks during the concurrent race.',
            );
        }

        ksort($expectedCounts);
        Assert::assertSame($expectedCounts, self::tally($outcomes));
    }

    /** Returns the 5-character SQLSTATE from the first `PDOException` in `$e`'s chain, or null. */
    public static function sqlState(Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }

            $state = $current->errorInfo[0] ?? $current->getCode();

            if (is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1) {
                return $state;
            }
        }

        return null;
    }

    /** Reads `pg_stat_database.deadlocks` for the active PostgreSQL test database after clearing the stats snapshot. */
    public static function pgDeadlocks(?string $connection = null): int
    {
        $database = self::databaseManager();

        if ($database === null) {
            throw new RuntimeException('DatabaseManager is not bound in the container.');
        }

        $conn = $database->connection($connection);
        $conn->select('select pg_stat_clear_snapshot()');
        $row = $conn->selectOne('select deadlocks from pg_stat_database where datname = current_database()');

        if (! is_object($row) || ! property_exists($row, 'deadlocks') || ! is_numeric($row->deadlocks)) {
            throw new RuntimeException('Unable to read pg_stat_database.deadlocks.');
        }

        return (int) $row->deadlocks;
    }

    /**
     * Counts PostgreSQL deadlocks recorded since `$before`, waiting briefly for exited worker backends to flush stats.
     */
    public static function deadlocksSince(int $before, ?string $connection = null, int $maxWaitMs = 2000): int
    {
        $deadlineNs = hrtime(true) + (max(0, $maxWaitMs) * 1_000_000);
        $last = self::pgDeadlocks($connection);
        $steady = 0;

        while ($steady < 3 && hrtime(true) < $deadlineNs) {
            usleep(200_000);
            $current = self::pgDeadlocks($connection);
            $steady = $current === $last ? $steady + 1 : 0;
            $last = $current;
        }

        return $last - $before;
    }

    private static function databaseManager(): ?DatabaseManager
    {
        $container = Container::getInstance();

        return $container->bound(DatabaseManager::class)
            ? $container->make(DatabaseManager::class)
            : null;
    }

    private static function disconnect(DatabaseManager $database): void
    {
        foreach (array_keys($database->getConnections()) as $name) {
            $database->disconnect($name);
        }
    }
}
