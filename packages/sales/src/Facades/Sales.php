<?php

declare(strict_types=1);

namespace Commerz\Sales\Facades;

use Commerz\Attributes\Api;
use Commerz\Sales\Carts\Contracts\Carts;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Carts carts()
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
