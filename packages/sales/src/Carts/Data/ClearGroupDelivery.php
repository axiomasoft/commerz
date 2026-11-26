<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ClearGroupDelivery
{
    public function __construct(
        public CartId $cart,
        public string $groupKey,
    ) {
        if (trim($groupKey) === '' || strlen($groupKey) > 64) {
            throw new InvalidCommand('groupKey', 'must be 1..64 bytes', 'sales.invalid_delivery_method');
        }
    }
}
