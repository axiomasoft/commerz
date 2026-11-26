<?php

declare(strict_types=1);

namespace Commerz\Time;

use Commerz\Time\Contracts\Clock;
use Commerz\Time\Internal\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Formats UTC instants for PostgreSQL `timestamptz(6)` columns using the active {@see Clock}.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class PgTimestamp
{
    public const string FORMAT = 'Y-m-d H:i:s.uP';

    public static function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function now(?Clock $clock = null): string
    {
        return self::format(self::current($clock));
    }

    public static function current(?Clock $clock = null): DateTimeImmutable
    {
        $resolved = $clock ?? self::resolveClock();

        return $resolved->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private static function resolveClock(): Clock
    {
        $container = Container::getInstance();

        if ($container->bound(Clock::class)) {
            return $container->make(Clock::class);
        }

        return new SystemClock;
    }
}
