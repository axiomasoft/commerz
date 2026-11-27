<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Concurrency\Support;

use Closure;
use Commerz\Operations\Contracts\Transactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The deadlock and lock-timeout detector of the cross-package concurrency suite (assembly-design#concurrency).
 *
 * `Transactions::run` never retries, so a lock cycle surfaces in a worker as 40P01 (deadlock) or 55P03 (lock
 * timeout). A worker turns only the domain errors it expects, by class, into an outcome string; anything else becomes
 * `error:<SQLSTATE or class>` and fails the test. `pg_stat_database.deadlocks` must not grow either.
 */
final class Outcome
{
    /** Below `lock_timeout` (3 s in `Transactions::run`): a cycle shows as 40P01, not as 55P03. */
    private const string DEADLOCK_TIMEOUT = '100ms';

    /** How long a contender waits before it starts, so that the {@see self::gate()} holds its row first. */
    public const int CONTENDER_DELAY_US = 150_000;

    /**
     * Runs one worker's call on its own connection.
     *
     * @param  Closure(): string  $call  returns the outcome of a success, e.g. 'ok'
     * @param  array<class-string<Throwable>, string>  $expected  domain error class => outcome
     */
    public static function of(Closure $call, array $expected = []): string
    {
        DB::statement("set deadlock_timeout = '".self::DEADLOCK_TIMEOUT."'");

        /** @var object{deadlock_timeout: string}|null $configured */
        $configured = DB::selectOne('show deadlock_timeout');
        if ($configured?->deadlock_timeout !== self::DEADLOCK_TIMEOUT) {
            throw new RuntimeException('deadlock_timeout could not be set for the worker session.');
        }

        try {
            return $call();
        } catch (Throwable $e) {
            foreach ($expected as $class => $outcome) {
                if ($e instanceof $class) {
                    return $outcome;
                }
            }

            return 'error:'.(self::sqlState($e) ?? $e::class);
        }
    }

    /**
     * Makes a race certain instead of likely: holds one row `FOR UPDATE` for `$holdMs` so that the other workers, which
     * start {@see self::CONTENDER_DELAY_US} later, queue on it together with whatever they locked before; then reports
     * how many sessions of this database were waiting for a lock when it let go (`gate:<n>`).
     */
    public static function gate(string $table, string $id, int $holdMs = 1500): string
    {
        return app(Transactions::class)->run(static function () use ($table, $id, $holdMs): string {
            DB::select("select id from {$table} where id = ? for update", [$id]);
            usleep($holdMs * 1000);
            /** @var object{n: int|string}|null $waiting */
            $waiting = DB::selectOne('select count(distinct l.pid) as n from pg_locks l join pg_stat_activity a on a.pid = l.pid'
                .' where not l.granted and a.datname = current_database()');

            $waitingSessions = $waiting === null ? 0 : $waiting->n;

            return 'gate:'.(int) $waitingSessions;
        });
    }

    /** @param  list<string>  $outcomes  the number of sessions the gate saw waiting */
    public static function waiting(array $outcomes): int
    {
        foreach ($outcomes as $outcome) {
            if (str_starts_with($outcome, 'gate:')) {
                return (int) substr($outcome, 5);
            }
        }

        throw new RuntimeException('No gate outcome.');
    }

    /** The SQLSTATE of the first database error in the chain, or null. */
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

    /** The deadlocks PostgreSQL has counted in this database so far. */
    public static function deadlocks(): int
    {
        DB::select('select pg_stat_clear_snapshot()');

        /** @var object{deadlocks: int|string}|null $row */
        $row = DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()');

        $deadlocks = $row === null ? 0 : $row->deadlocks;

        return (int) $deadlocks;
    }

    /**
     * The deadlocks counted since `$before`, once the workers' backends have reported: a backend flushes its statistics
     * when it exits, which lags behind the exit of its PHP process, so the counter is polled until it holds still.
     */
    public static function deadlocksSince(int $before): int
    {
        $deadline = microtime(true) + 2.0;
        $last = self::deadlocks();
        $steady = 0;

        while ($steady < 3 && microtime(true) < $deadline) {
            usleep(200_000);
            $current = self::deadlocks();
            $steady = $current === $last ? $steady + 1 : 0;
            $last = $current;
        }

        return $last - $before;
    }

    /**
     * @param  list<string>  $outcomes
     * @return list<string> the outcomes that are not expected domain results
     */
    public static function failures(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (string $outcome): bool => str_starts_with($outcome, 'error:')));
    }

    /**
     * What every scenario ends with: no worker failed outside the expected domain errors, PostgreSQL counted no
     * deadlock, and the stock and promotion counters agree with their journals (the reconcile commands only check).
     *
     * @param  list<string>  $outcomes
     */
    public static function assertSettled(array $outcomes, int $deadlocksBefore): void
    {
        expect(self::failures($outcomes))->toBe([])
            ->and(self::deadlocksSince($deadlocksBefore))->toBe(0);

        foreach (['commerz:inventory:reconcile' => 'Stock is consistent.', 'commerz:promotions:reconcile' => 'Promotions are consistent.'] as $command => $clean) {
            $exit = Artisan::call($command);
            $output = Artisan::output();

            expect($exit)->toBe(0, $output)
                ->and($output)->toContain($clean);
        }
    }
}
