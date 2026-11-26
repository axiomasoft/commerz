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
        public ?string $hsCode = null,
        public ?string $originCountryCode = null,
        public ?string $material = null,
        public ?string $customsDescription = null,
    ) {
        InventoryCodes::sku($sku);
        Unit::assert($unit);
        self::assertName($name);
        self::assertCustoms($hsCode, $originCountryCode, $material, $customsDescription);
    }

    /** @internal */
    public static function assertName(?string $name): void
    {
        if ($name !== null && (trim($name) === '' || strlen($name) > 191)) {
            throw new InvalidCommand('name', 'must be 1 to 191 bytes');
        }
    }

    /** @internal */
    public static function assertCustoms(?string $hsCode, ?string $originCountryCode, ?string $material, ?string $customsDescription): void
    {
        if ($hsCode !== null && (trim($hsCode) === '' || strlen($hsCode) > 32)) {
            throw new InvalidCommand('hsCode', 'must be 1 to 32 bytes');
        }

        if ($originCountryCode !== null && preg_match('/\A[A-Z]{2}\z/', $originCountryCode) !== 1) {
            throw new InvalidCommand('originCountryCode', "'{$originCountryCode}' is not an ISO 3166-1 alpha-2 code");
        }

        if ($material !== null && (trim($material) === '' || strlen($material) > 191)) {
            throw new InvalidCommand('material', 'must be 1 to 191 bytes');
        }

        if ($customsDescription !== null && (trim($customsDescription) === '' || strlen($customsDescription) > 500)) {
            throw new InvalidCommand('customsDescription', 'must be 1 to 500 bytes');
        }
    }
}
