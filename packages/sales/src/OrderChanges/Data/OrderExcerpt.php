<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Data\OrderView;
use Packman\Attributes\Api;

/** Financial and line snapshot of an order before or after applying an OrderChange. */
#[Api(since: '0.1')]
final readonly class OrderExcerpt
{
    /**
     * @param  list<OrderExcerptLine>  $lines
     * @param  list<Adjustment>  $orderAdjustments
     * @param  list<Adjustment>  $deliveryAdjustments
     * @param  list<TaxLine>  $deliveryTaxLines
     */
    public function __construct(
        public OrderId $orderId,
        public int $orderVersion,
        public Currency $currency,
        public array $lines,
        public array $orderAdjustments,
        public ?DeliveryCharge $delivery,
        public array $deliveryAdjustments,
        public array $deliveryTaxLines,
        public QuoteTotals $totals,
        public Money $amountDue,
    ) {}

    public static function fromOrder(OrderView $order): self
    {
        return new self(
            orderId: $order->id,
            orderVersion: $order->version,
            currency: $order->currency,
            lines: array_map(
                static fn (OrderLineView $line): OrderExcerptLine => OrderExcerptLine::fromOrderLine($line),
                $order->lines,
            ),
            orderAdjustments: $order->orderAdjustments,
            delivery: $order->delivery,
            deliveryAdjustments: $order->deliveryAdjustments,
            deliveryTaxLines: $order->deliveryTaxLines,
            totals: $order->totals,
            amountDue: $order->amountDue,
        );
    }

    public function line(OrderLineId $id): ?OrderExcerptLine
    {
        return array_find($this->lines, static fn (OrderExcerptLine $line): bool => $line->id->equals($id));
    }
}
