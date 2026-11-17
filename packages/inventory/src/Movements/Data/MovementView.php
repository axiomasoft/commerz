<?php

declare(strict_types=1);

namespace Commerz\Inventory\Movements\Data;

use Commerz\Identity\ActorRef;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Stock\Enums\MovementKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** One journal row: the on-hand change of a level and the balance it left. */
#[Api(since: '0.1')]
final readonly class MovementView
{
    public function __construct(
        public int $id,
        public StockItemId $item,
        public LocationId $location,
        public string $locationCode,
        public MovementKind $kind,
        public int $delta,
        public int $balanceAfter,
        public ?string $reference,
        public ?string $reason,
        public ?ActorRef $actor,
        public DateTimeImmutable $occurredAt,
    ) {}
}
