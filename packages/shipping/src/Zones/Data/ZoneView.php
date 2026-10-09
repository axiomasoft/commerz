<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Attributes\Api;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class ZoneView
{
    /** @param  list<ZoneMember>  $members by country, then region, then prefix; a missing region or prefix first */
    public function __construct(
        public ZoneId $id,
        public string $code,
        public string $name,
        public ZoneStatus $status,
        public array $members,
        public DateTimeImmutable $updatedAt,
    ) {}
}
