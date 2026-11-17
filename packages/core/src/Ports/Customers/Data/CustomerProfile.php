<?php

declare(strict_types=1);

namespace Commerz\Ports\Customers\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CustomerProfile
{
    /** @var list<string> */
    public array $groups;

    /** @param  list<string>  $groups  drive prices and promotions, [a-z0-9_.-]{1,64} each */
    public function __construct(
        public CustomerRef $ref,
        array $groups = [],
        public ?string $email = null,
        public ?string $name = null,
        public bool $taxExempt = false,
    ) {
        foreach ($groups as $group) {
            if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $group) !== 1) {
                throw new InvalidCommand('groups', "'{$group}' must match [a-z0-9_.-]{1,64}", 'customers.invalid_group');
            }
        }

        $this->groups = array_values(array_unique($groups));
    }
}
