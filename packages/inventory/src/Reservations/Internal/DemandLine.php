<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\NeedComponent;

/** A requested sellable resolved through its links: stock to place, unlimited (`components` null) or unavailable. */
final readonly class DemandLine
{
    /** @param  list<NeedComponent>|null  $components  tracked and backorder items; null when stock does not limit it */
    public function __construct(
        public SellableRef $sellable,
        public int $units,
        public ?array $components,
        public bool $unavailable = false,
    ) {}

    public function stocked(): bool
    {
        return ! $this->unavailable && $this->components !== null;
    }

    public function need(): AllocationNeed
    {
        return new AllocationNeed($this->sellable, $this->units, $this->components ?? []);
    }
}
