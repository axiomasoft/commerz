<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Inventory\Locations\Enums\LocationKind;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class LocationView
{
    public function __construct(
        public LocationId $id,
        public string $code,
        public string $name,
        public LocationKind $kind,
        public LocationStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
