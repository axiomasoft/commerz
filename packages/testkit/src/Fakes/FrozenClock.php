<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Time\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Packman\Attributes\Api;

/**
 * Deterministic UTC clock for tests; freeze at a known instant and advance explicitly.
 */
#[Api(since: '0.1')]
final class FrozenClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct(DateTimeImmutable|string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->current = self::normalize($now);
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(DateTimeImmutable|string $now): self
    {
        $this->current = self::normalize($now);

        return $this;
    }

    public function advance(DateInterval|string $interval): self
    {
        $delta = $interval instanceof DateInterval
            ? $interval
            : DateInterval::createFromDateString($interval);

        $this->current = $this->current->add($delta);

        return $this;
    }

    private static function normalize(DateTimeImmutable|string $instant): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return $instant instanceof DateTimeImmutable
            ? $instant->setTimezone($utc)
            : new DateTimeImmutable($instant, $utc);
    }
}
