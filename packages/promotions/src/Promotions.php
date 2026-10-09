<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Commerz\Attributes\Api;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;

/** Root of the promotions API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Promotions
{
    public function __construct(private PromotionDefinitions $promotions) {}

    public function promotions(): PromotionDefinitions
    {
        return $this->promotions;
    }
}
