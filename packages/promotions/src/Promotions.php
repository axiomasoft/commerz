<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Commerz\Promotions\Coupons\Contracts\Coupons;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;
use Packman\Attributes\Api;

/** Root of the promotions API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Promotions
{
    public function __construct(private PromotionDefinitions $promotions, private Coupons $coupons) {}

    public function promotions(): PromotionDefinitions
    {
        return $this->promotions;
    }

    public function coupons(): Coupons
    {
        return $this->coupons;
    }
}
