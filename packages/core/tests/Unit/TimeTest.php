<?php

declare(strict_types=1);

use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\Internal\SystemClock;
use Commerz\Time\PgTimestamp;

it('provides UTC instants from SystemClock and formats them for PostgreSQL', function (): void {
    $clock = new SystemClock;
    $now = $clock->now();

    expect($clock)->toBeInstanceOf(Clock::class)
        ->and($now->getTimezone()->getName())->toBe('UTC')
        ->and(PgTimestamp::format($now))->toMatch('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\+00:00\z/');
});

it('freezes and advances time deterministically with FrozenClock', function (): void {
    $clock = new FrozenClock('2026-05-01T12:30:00+02:00');

    expect(PgTimestamp::format($clock->now()))->toBe('2026-05-01 10:30:00.000000+00:00')
        ->and(PgTimestamp::now($clock))->toBe('2026-05-01 10:30:00.000000+00:00');

    $clock->advance('15 minutes');
    expect(PgTimestamp::now($clock))->toBe('2026-05-01 10:45:00.000000+00:00');

    $clock->set(new DateTimeImmutable('2026-06-01T00:00:00Z'));
    expect(PgTimestamp::now($clock))->toBe('2026-06-01 00:00:00.000000+00:00');
});
