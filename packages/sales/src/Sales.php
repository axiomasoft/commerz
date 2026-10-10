<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Commerz\Attributes\Api;
use Commerz\Sales\Carts\Contracts\Carts;

/** Root of the sales API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Sales
{
    public function __construct(private Carts $carts) {}

    public function carts(): Carts
    {
        return $this->carts;
    }
}
