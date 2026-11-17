<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StockItemView
{
    public function __construct(
        public StockItemId $id,
        public string $sku,
        public ?string $name,
        public string $unit,
        public StockPolicy $policy,
        public StockItemStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
