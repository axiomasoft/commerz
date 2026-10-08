<?php

declare(strict_types=1);

namespace Commerz\Promotions\Exceptions;

use Commerz\Attributes\Api;
use DomainException;
use RuntimeException;

#[Api(since: '1.0')]
final class InvalidPromotionConfig extends DomainException
{
    public static function field(string $field, string $expectation): self
    {
        return new self("Invalid promotion config [{$field}]: expected {$expectation}.");
    }
}

/** Лимит/бюджет закончился между расчётом корзины и размещением: заказ не создаётся, итоги пересчитываются. */
#[Api(since: '1.0')]
final class PromotionNoLongerAvailable extends RuntimeException
{
    public static function for(string $promotionId, string $reason): self
    {
        return new self("Promotion [{$promotionId}] is no longer available: {$reason}.");
    }
}
