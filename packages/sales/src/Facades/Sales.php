<?php

declare(strict_types=1);

namespace Commerz\Sales\Facades;

use Commerz\Attributes\Api;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Orders\Contracts\Orders;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Carts carts()
 * @method static Checkout checkout()
 * @method static Orders orders()
 *
 * @see \Commerz\Sales\Sales
 */
#[Api(since: '0.1')]
final class Sales extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Sales\Sales::class;
    }
}
