<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Stock\Data\StockLevelView;
use DateTimeImmutable;

/** A `stock_levels` row as {@see StockLedger::lock()} returns it, with the item and location it belongs to. */
final readonly class LevelRow
{
    public function __construct(
        public string $id,
        public string $item,
        public string $location,
        public string $sku,
        public string $locationCode,
        public StockPolicy $policy,
        public bool $itemArchived,
        public bool $locationArchived,
        public int $onHand,
        public int $reserved,
        public int $safetyStock,
        public int $version,
        public string $updatedAt,
    ) {}

    /** Key of the `(item, location)` pair in the maps the ledger returns. */
    public static function key(string $item, string $location): string
    {
        return $item.'|'.$location;
    }

    public function levelKey(): string
    {
        return self::key($this->item, $this->location);
    }

    /** The same level after an UPDATE … RETURNING of its quantities. */
    public function with(object $returned): self
    {
        return new self(
            $this->id,
            $this->item,
            $this->location,
            $this->sku,
            $this->locationCode,
            $this->policy,
            $this->itemArchived,
            $this->locationArchived,
            Rows::int($returned, 'on_hand'),
            Rows::int($returned, 'reserved'),
            Rows::int($returned, 'safety_stock'),
            Rows::int($returned, 'version'),
            Rows::string($returned, 'updated_at'),
        );
    }

    /** `reserved + safety_stock − on_hand` when positive, else 0; saturates instead of overflowing. */
    public function shortfall(): int
    {
        $available = $this->view()->available;

        return match (true) {
            $available >= 0 => 0,
            $available === PHP_INT_MIN => PHP_INT_MAX,
            default => -$available,
        };
    }

    public function view(): StockLevelView
    {
        return new StockLevelView(
            new StockItemId($this->item),
            $this->sku,
            new LocationId($this->location),
            $this->locationCode,
            $this->onHand,
            $this->reserved,
            $this->safetyStock,
            $this->version,
            new DateTimeImmutable($this->updatedAt),
        );
    }
}
