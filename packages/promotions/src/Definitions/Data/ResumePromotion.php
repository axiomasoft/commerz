<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ResumePromotion
{
    public function __construct(public PromotionId $promotion) {}
}
