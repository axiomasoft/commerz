<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Enums\AddressRole;
use Packman\Attributes\Api;

/** Sets the address of a role; `null` clears it. */
#[Api(since: '0.1')]
final readonly class SetCartAddress
{
    public function __construct(public CartId $cart, public AddressRole $role, public ?Address $address) {}
}
