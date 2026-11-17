<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Data;

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Packman\Attributes\Api;

/**
 * Units of a sellable held on one stock item at one location; a kit unit has one line per component, all with the
 * same units. Units are sellable units: the stock held is `outstanding × quantityPerUnit`.
 * `units = consumed + released + expired + outstanding` (INV-04).
 */
#[Api(since: '0.1')]
final readonly class ReservationLineView
{
    public function __construct(
        public SellableRef $sellable,
        public StockItemId $item,
        public string $sku,
        public LocationId $location,
        public string $locationCode,
        public int $quantityPerUnit,
        public int $units,
        public int $consumed,
        public int $released,
        public int $expired,
        public int $outstanding,
    ) {}
}
