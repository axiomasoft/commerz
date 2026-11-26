<?php

declare(strict_types=1);

namespace Commerz\Sales\Facades;

use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Returns\Contracts\Returns;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static Carts carts()
 * @method static Checkout checkout()
 * @method static Orders orders()
 * @method static OrderChanges orderChanges()
 * @method static Payments payments()
 * @method static Fulfillments fulfillments()
 * @method static Returns returns()
 * @method static Refunds refunds()
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
