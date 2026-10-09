<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use DateTimeImmutable;
use DateTimeZone;

/** Write timestamp in the format the `timestamptz(6)` columns take. */
final class Clock
{
    public static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
