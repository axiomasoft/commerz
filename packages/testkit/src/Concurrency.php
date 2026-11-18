<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Api;
use Packman\Testkit\Concurrency\Process\Concurrency as PackmanConcurrency;
use RuntimeException;

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

    private static function disconnect(DatabaseManager $database): void
    {
        foreach (array_keys($database->getConnections()) as $name) {
            $database->disconnect($name);
        }
    }
}
