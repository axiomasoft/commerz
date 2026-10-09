<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\Rows;

/** A `stock_reservation_lines` row of the current generation; units are sellable units. */
final readonly class LineRow
{
    public function __construct(
        public string $id,
        public string $sellable,
        public string $item,
        public string $location,
        public string $locationCode,
        public int $quantityPerUnit,
        public int $units,
        public int $consumed,
        public int $released,
        public int $expired,
        public int $position,
    ) {}

    public static function from(object $row): self
    {
        return new self(
            Rows::string($row, 'id'),
            Rows::string($row, 'sellable_ref'),
            Rows::string($row, 'stock_item_id'),
            Rows::string($row, 'location_id'),
            Rows::string($row, 'location_code'),
            Rows::int($row, 'quantity_per_unit'),
            Rows::int($row, 'units'),
            Rows::int($row, 'consumed_units'),
            Rows::int($row, 'released_units'),
            Rows::int($row, 'expired_units'),
            Rows::int($row, 'position'),
        );
    }

    public function outstanding(): int
    {
        return $this->units - $this->consumed - $this->released - $this->expired;
    }

    public function levelKey(): string
    {
        return LevelRow::key($this->item, $this->location);
    }

    /** Lines of one allocation: the components of the same sellable units at one location. */
    public function groupKey(): string
    {
        return $this->sellable.'|'.$this->location;
    }

    /**
     * Sellable units the changes moved: per allocation the most any of its component lines moved, summed by sellable.
     *
     * @param  list<array{0: self, 1: int}>  $changes  line and the units it moved
     * @return array<string, int> sellable ref => units
     */
    public static function sellableUnits(array $changes): array
    {
        $groups = [];

        foreach ($changes as [$line, $units]) {
            $groups[$line->sellable][$line->location] = max($groups[$line->sellable][$line->location] ?? 0, $units);
        }

        return array_filter(array_map(array_sum(...), $groups), static fn (int $units): bool => $units > 0);
    }
}
