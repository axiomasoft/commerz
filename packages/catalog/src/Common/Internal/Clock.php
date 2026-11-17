<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

/** Write timestamp in the format the `timestamptz(6)` columns take. */
#[Internal]
final class Clock
{
    public static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
