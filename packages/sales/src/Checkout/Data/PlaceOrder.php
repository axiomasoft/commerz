<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Data;

use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Carts\Data\CartId;
use Packman\Attributes\Api;

/**
 * Places the cart as an order. `$expectedTotal` is the total the buyer accepted (`Carts::quote`); the order is placed
 * only at that total. With an idempotency key a repeated request returns the same order.
 */
#[Api(since: '0.1')]
final readonly class PlaceOrder
{
    public function __construct(
        public CartId $cart,
        public Money $expectedTotal,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {}
}
