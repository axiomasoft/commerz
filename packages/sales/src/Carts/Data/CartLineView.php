<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class CartLineView
{
    public function __construct(public string $id, public SellableRef $sellable, public int $quantity) {}
}
