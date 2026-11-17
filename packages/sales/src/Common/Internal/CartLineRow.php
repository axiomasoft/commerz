<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Identity\SellableRef;
use Packman\Attributes\Internal;

/** A stored cart line. */
#[Internal]
final readonly class CartLineRow
{
    public function __construct(
        public string $id,
        public SellableRef $sellable,
        public int $quantity,
        public int $position,
    ) {}
}
