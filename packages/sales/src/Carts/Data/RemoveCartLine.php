<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RemoveCartLine
{
    public function __construct(public CartId $cart, public string $lineId) {}
}
