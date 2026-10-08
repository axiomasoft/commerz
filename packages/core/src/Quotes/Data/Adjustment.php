<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Quotes\Enums\AdjustmentTarget;

/**
 * A price reduction with its reason. `$target` says where it was declared; `$lineId` names the line it is carried
 * by: required for `Line`, never set for `Delivery`, and set for `Order` only on the per-line shares the totals stage
 * allocates (a quote result line lists its own adjustments and its shares of order-level ones).
 */
#[Api(since: '0.1')]
final readonly class Adjustment
{
    /**
     * @param  Money  $amount  negative
     * @param  string  $source  key of the stage or rule that made it, e.g. `promotions.cart`
     * @param  string  $explanation  human-readable reason, e.g. 'Autumn −10%'
     */
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public Money $amount,
        public string $source,
        public string $explanation,
        public ?string $promotionId = null,
        public ?string $couponCode = null,
    ) {
        if (! $amount->isNegative()) {
            throw new InvalidCommand('amount', 'an adjustment must be negative', 'quotes.invalid_adjustment');
        }

        if ($target === AdjustmentTarget::Line && $lineId === null) {
            throw new InvalidCommand('lineId', 'a line adjustment needs a line id', 'quotes.invalid_adjustment');
        }

        if ($target === AdjustmentTarget::Delivery && $lineId !== null) {
            throw new InvalidCommand('lineId', 'a delivery adjustment has no line id', 'quotes.invalid_adjustment');
        }

        if ($source === '') {
            throw new InvalidCommand('source', 'must not be empty', 'quotes.invalid_adjustment');
        }
    }

    public static function line(string $lineId, Money $amount, string $source, string $explanation, ?string $promotionId = null, ?string $couponCode = null): self
    {
        return new self(AdjustmentTarget::Line, $lineId, $amount, $source, $explanation, $promotionId, $couponCode);
    }

    public static function order(Money $amount, string $source, string $explanation, ?string $promotionId = null, ?string $couponCode = null): self
    {
        return new self(AdjustmentTarget::Order, null, $amount, $source, $explanation, $promotionId, $couponCode);
    }

    public static function delivery(Money $amount, string $source, string $explanation, ?string $promotionId = null, ?string $couponCode = null): self
    {
        return new self(AdjustmentTarget::Delivery, null, $amount, $source, $explanation, $promotionId, $couponCode);
    }

    /** This order-level adjustment's share carried by one line. */
    public function allocatedTo(string $lineId, Money $share): self
    {
        if ($this->target !== AdjustmentTarget::Order || $this->lineId !== null) {
            throw new InvalidCommand('target', 'only an order-level adjustment is allocated', 'quotes.invalid_adjustment');
        }

        return new self(AdjustmentTarget::Order, $lineId, $share, $this->source, $this->explanation, $this->promotionId, $this->couponCode);
    }
}
