<?php

declare(strict_types=1);

use Commerz\Testkit\Concurrency;
use PHPUnit\Framework\AssertionFailedError;

it('rejects non-positive worker counts', function (): void {
    expect(fn () => Concurrency::run(0, static fn (int $worker): int => $worker))
        ->toThrow(RuntimeException::class, 'at least one worker');
});

it('runs parallel workers via Packman concurrency and returns results in worker order', function (): void {
    $results = Concurrency::run(4, static fn (int $worker): string => "worker-{$worker}");

    expect($results)->toBe(['worker-0', 'worker-1', 'worker-2', 'worker-3']);
})->skip(! function_exists('pcntl_fork'), 'pcntl is required.');

it('surfaces worker exceptions with the worker index and exception class', function (): void {
    expect(fn () => Concurrency::run(2, static fn (int $worker): int => $worker === 1 ? throw new DomainException('worker broke') : $worker))
        ->toThrow(RuntimeException::class, 'worker 1: DomainException: worker broke');
})->skip(! function_exists('pcntl_fork'), 'pcntl is required.');

it('tallies race outcomes sorted alphabetically and extracts unexpected errors', function (): void {
    $outcomes = ['rejected', 'ok', 'error:40P01', 'ok', 'rejected'];

    expect(Concurrency::tally($outcomes))->toBe([
        'error:40P01' => 1,
        'ok' => 2,
        'rejected' => 2,
    ])->and(Concurrency::unexpectedErrors($outcomes))->toBe(['error:40P01']);
});

it('extracts 5-character SQLSTATE from nested PDOException chains', function (): void {
    $pdo = new PDOException('deadlock detected');
    $pdo->errorInfo = ['40P01', 7, 'ERROR: deadlock detected'];
    $wrapped = new RuntimeException('tx failed', 0, $pdo);

    expect(Concurrency::sqlState($wrapped))->toBe('40P01')
        ->and(Concurrency::sqlState(new RuntimeException('no pdo')))->toBeNull();
});

it('races parallel workers mapping expected exceptions via strings and closures', function (): void {
    $outcomes = Concurrency::race(
        workers: 4,
        work: static fn (int $worker): string => match ($worker) {
            0 => 'ok',
            1 => throw new DomainException('conflict'),
            2 => throw new InvalidArgumentException('stale'),
            default => throw new LogicException('boom'),
        },
        expected: [
            DomainException::class => 'domain_conflict',
            InvalidArgumentException::class => static fn (Throwable $e): string => 'mapped:'.$e->getMessage(),
        ],
    );

    expect($outcomes)->toBe([
        'ok',
        'domain_conflict',
        'mapped:stale',
        'error:LogicException:boom',
    ]);
})->skip(! function_exists('pcntl_fork'), 'pcntl is required.');

it('validates deadlockTimeout format and asserts settled race counts', function (): void {
    expect(fn () => Concurrency::race(2, static fn (): string => 'ok', deadlockTimeout: 'bad'))
        ->toThrow(RuntimeException::class, 'Invalid PostgreSQL deadlock_timeout');

    Concurrency::assertRaceSettled(['ok', 'conflict', 'conflict'], ['conflict' => 2, 'ok' => 1]);

    expect(fn () => Concurrency::assertRaceSettled(['ok', 'error:40P01'], ['ok' => 1]))
        ->toThrow(AssertionFailedError::class, 'Unexpected worker errors');
});
