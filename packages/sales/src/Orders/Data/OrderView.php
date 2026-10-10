<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\StoreId;
use DateTimeImmutable;

/**
 * A placed order: an immutable snapshot of the accepted quote (ORD-02) with its three states, which are derived from
 * the amounts and the ledger of the lines.
 */
#[Api(since: '0.1')]
final readonly class OrderView
{
    /** Same as `$totals->total`. */
    public Money $total;

    /**
     * @param  CartKind  $origin  the kind of the cart it was placed from: `draft` for an order assembled by an operator
     * @param  list<string>  $customerGroups
     * @param  list<OrderLineView>  $lines  in the order of the cart
     * @param  list<Adjustment>  $orderAdjustments  order-level adjustments as declared (their shares are on the lines)
     * @param  list<Adjustment>  $deliveryAdjustments
     * @param  list<TaxLine>  $deliveryTaxLines
     * @param  Money  $amountDue  the total less the value of the cancelled units
     * @param  list<string>  $codes
     * @param  string  $quoteFingerprint  of the quote the order was placed from
     */
    public function __construct(
        public OrderId $id,
        public string $number,
        public StoreId $store,
        public ChannelId $channel,
        public CartId $cart,
        public CartKind $origin,
        public OrderState $state,
        public PaymentState $paymentState,
        public FulfillmentState $fulfillmentState,
        public ?CancelReason $cancelReason,
        public Currency $currency,
        public string $locale,
        public ?CustomerRef $customer,
        public array $customerGroups,
        public ?string $customerEmail,
        public ?string $customerName,
        public array $lines,
        public array $orderAdjustments,
        public ?DeliveryCharge $delivery,
        public array $deliveryAdjustments,
        public array $deliveryTaxLines,
        public QuoteTotals $totals,
        public Money $amountDue,
        public Money $authorized,
        public Money $captured,
        public Money $refunded,
        public array $codes,
        public ?Address $shippingAddress,
        public ?Address $billingAddress,
        public string $quoteFingerprint,
        public ?DateTimeImmutable $paymentDueAt,
        public DateTimeImmutable $placedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $cancelledAt,
    ) {
        $this->total = $totals->total;
    }

    public function line(OrderLineId $id): ?OrderLineView
    {
        return array_find($this->lines, static fn (OrderLineView $line): bool => $line->id->equals($id));
    }
}
