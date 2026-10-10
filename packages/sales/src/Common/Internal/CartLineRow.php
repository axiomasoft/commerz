<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Identity\SellableRef;

/** A stored cart line. */
final readonly class CartLineRow
{
    public function __construct(
        public string $id,
        public SellableRef $sellable,
        public int $quantity,
        public int $position,
    ) {}
}
