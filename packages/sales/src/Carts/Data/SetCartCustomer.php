<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;

/** Sets or clears (`null`) the customer and the customer groups that price lists and promotions see. */
#[Api(since: '0.1')]
final readonly class SetCartCustomer
{
    /** @var list<string> */
    public array $groups;

    /** @param  list<string>  $groups */
    public function __construct(public CartId $cart, public ?CustomerRef $customer, array $groups = [])
    {
        foreach ($groups as $group) {
            if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $group) !== 1) {
                throw new InvalidCommand('groups', "'{$group}' must match [a-z0-9_.-]{1,64}", 'sales.invalid_group');
            }
        }

        $this->groups = array_values(array_unique($groups));
    }
}
