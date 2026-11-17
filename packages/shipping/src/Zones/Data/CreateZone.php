<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Shipping\Common\Data\ShippingCodes;
use Packman\Attributes\Api;

/** A zone with a unique code. Zones may overlap: the same member can belong to several zones. */
#[Api(since: '0.1')]
final readonly class CreateZone
{
    /** @param  list<ZoneMember>  $members */
    public function __construct(
        public string $code,
        public string $name,
        public array $members = [],
    ) {
        ShippingCodes::code('code', $code);
        self::assertName($name);
        ZoneMember::assertUnique($members);
    }

    /** @internal */
    public static function assertName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 191) {
            throw new InvalidCommand('name', 'must be 1 to 191 characters', 'shipping.invalid_name');
        }
    }
}
