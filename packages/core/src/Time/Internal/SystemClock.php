<?php

declare(strict_types=1);

namespace Commerz\Time\Internal;

use Commerz\Time\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Packman\Attributes\Internal;

/** Wall-clock UTC time source for production runtime. */
#[Internal]
final class SystemClock implements Clock
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
