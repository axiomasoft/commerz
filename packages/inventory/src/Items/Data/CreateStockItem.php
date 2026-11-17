<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Measures\Unit;
use Packman\Attributes\Api;

/** Creates a stock item; its unit is the base unit of every quantity and never changes. */
#[Api(since: '0.1')]
final readonly class CreateStockItem
{
    public function __construct(
        public string $sku,
        public ?string $name = null,
        public string $unit = 'pcs',
        public StockPolicy $policy = StockPolicy::Tracked,
    ) {
        InventoryCodes::sku($sku);
        Unit::assert($unit);
        self::assertName($name);
    }

    /** @internal */
    public static function assertName(?string $name): void
    {
        if ($name !== null && (trim($name) === '' || strlen($name) > 191)) {
            throw new InvalidCommand('name', 'must be 1 to 191 bytes');
        }
    }
}
