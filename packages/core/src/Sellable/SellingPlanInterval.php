<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Commerz\Errors\InvalidCommand;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * Calendar cadence unit for recurring billing and delivery schedules.
 */
#[Api(since: '0.1')]
enum SellingPlanInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function addTo(DateTimeImmutable $anchor, int $count = 1): DateTimeImmutable
    {
        if ($count < 1) {
            throw new InvalidCommand('intervalCount', 'must be at least 1', 'sellable.invalid_interval');
        }

        $utc = $anchor->setTimezone(new DateTimeZone('UTC'));
        $spec = match ($this) {
            self::Day => "P{$count}D",
            self::Week => "P{$count}W",
            self::Month => "P{$count}M",
            self::Year => "P{$count}Y",
        };

        return $utc->add(new DateInterval($spec));
    }
}
