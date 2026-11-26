<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Money\Money;
use Commerz\Sales\Exchanges\Enums\ExchangeSettlement;
use Commerz\Sales\Exchanges\Enums\ExchangeStatus;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Enums\ReturnReason;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Combines an inbound {@see ReturnId} and an outbound {@see FulfillmentId} with exact net financial delta
 * (`netDelta = outboundTotal - returnTotal`).
 */
#[Api(since: '0.1')]
final readonly class OrderExchange
{
    /**
     * @param  list<ExchangeInboundLineView>  $inboundLines
     * @param  list<ExchangeOutboundLineView>  $outboundLines
     */
    public function __construct(
        public ExchangeId $id,
        public OrderId $order,
        public ReturnId $return,
        public ?FulfillmentId $fulfillment,
        public ExchangeStatus $status,
        public ExchangeSettlement $settlement,
        public ?ReturnReason $reason,
        public ?string $note,
        public array $inboundLines,
        public array $outboundLines,
        public Money $returnTotal,
        public Money $outboundSubtotal,
        public Money $outboundTaxTotal,
        public Money $outboundTotal,
        public Money $netDelta,
        public Money $amountDue,
        public Money $refundDue,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $cancelledAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
