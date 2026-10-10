<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Chooses a delivery method by its code; the quote prices it and rejects a method that cannot deliver. */
#[Api(since: '0.1')]
final readonly class SelectDelivery
{
    public function __construct(public CartId $cart, public string $methodCode)
    {
        if ($methodCode === '' || strlen($methodCode) > 64) {
            throw new InvalidCommand('methodCode', 'must be 1..64 bytes', 'sales.invalid_delivery_method');
        }
    }
}
