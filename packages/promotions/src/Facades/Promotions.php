<?php

declare(strict_types=1);

namespace Commerz\Promotions\Facades;

use Commerz\Attributes\Api;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PromotionDefinitions promotions()
 *
 * @see \Commerz\Promotions\Promotions
 */
#[Api(since: '0.1')]
final class Promotions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Promotions\Promotions::class;
    }
}
