<?php

declare(strict_types=1);

use Commerz\Testkit\Concurrency;

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
