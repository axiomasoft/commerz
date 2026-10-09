<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Locations\Enums\LocationKind;

#[Api(since: '0.1')]
final readonly class CreateLocation
{
    public function __construct(
        public string $code,
        public string $name,
        public LocationKind $kind = LocationKind::Warehouse,
    ) {
        InventoryCodes::code('code', $code);
        self::assertName($name);
    }

    /** @internal */
    public static function assertName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > 191) {
            throw new InvalidCommand('name', 'must be 1 to 191 bytes');
        }
    }
}
