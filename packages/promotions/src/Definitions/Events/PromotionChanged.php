<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Events;

use Commerz\Promotions\Definitions\Data\PromotionId;
use Packman\Attributes\Api;

/** A promotion was created, changed, paused, resumed, archived or had its channels changed in a committed operation. */
#[Api(since: '0.1')]
final readonly class PromotionChanged
{
    public function __construct(public PromotionId $promotion) {}
}
