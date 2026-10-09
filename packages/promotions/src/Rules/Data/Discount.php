<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Quotes\Enums\AdjustmentTarget;

/** What an effect takes off and where: a line, the order, or the delivery. The amount is positive; the engine makes the adjustment negative. */
#[Api(since: '0.1')]
final readonly class Discount
{
    private function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public Money $amount,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'a discount must not be negative', 'promotions.invalid_discount');
        }
    }

    public static function line(string $lineId, Money $amount): self
    {
        return new self(AdjustmentTarget::Line, $lineId, $amount);
    }

    public static function order(Money $amount): self
    {
        return new self(AdjustmentTarget::Order, null, $amount);
    }

    public static function delivery(Money $amount): self
    {
        return new self(AdjustmentTarget::Delivery, null, $amount);
    }
}
