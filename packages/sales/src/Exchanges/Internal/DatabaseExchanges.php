<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Exchanges\Contracts\Exchanges;
use Commerz\Sales\Exchanges\Data\CancelOrderClaim;
use Commerz\Sales\Exchanges\Data\CancelOrderExchange;
use Commerz\Sales\Exchanges\Data\ClaimId;
use Commerz\Sales\Exchanges\Data\ClaimItem;
use Commerz\Sales\Exchanges\Data\ClaimLineView;
use Commerz\Sales\Exchanges\Data\CompleteOrderExchange;
use Commerz\Sales\Exchanges\Data\CreateOrderClaim;
use Commerz\Sales\Exchanges\Data\CreateOrderExchange;
use Commerz\Sales\Exchanges\Data\ExchangeId;
use Commerz\Sales\Exchanges\Data\ExchangeInboundLineView;
use Commerz\Sales\Exchanges\Data\ExchangeOutboundItem;
use Commerz\Sales\Exchanges\Data\ExchangeOutboundLineView;
use Commerz\Sales\Exchanges\Data\FulfillOrderClaim;
use Commerz\Sales\Exchanges\Data\ListOrderClaims;
use Commerz\Sales\Exchanges\Data\ListOrderExchanges;
use Commerz\Sales\Exchanges\Data\OrderClaim;
use Commerz\Sales\Exchanges\Data\OrderExchange;
use Commerz\Sales\Exchanges\Enums\ClaimStatus;
use Commerz\Sales\Exchanges\Enums\ExchangeSettlement;
use Commerz\Sales\Exchanges\Enums\ExchangeStatus;
use Commerz\Sales\Exchanges\Events\OrderClaimCreated;
use Commerz\Sales\Exchanges\Events\OrderClaimFulfilled;
use Commerz\Sales\Exchanges\Events\OrderExchangeCompleted;
use Commerz\Sales\Exchanges\Events\OrderExchangeCreated;
use Commerz\Sales\Exchanges\Exceptions\ClaimNotOpen;
use Commerz\Sales\Exchanges\Exceptions\ExchangeNotOpen;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Events\FulfillmentCreated;
use Commerz\Sales\Fulfillments\Internal\FulfillmentRows;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Returns\Data\CancelReturn;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Commerz\Sales\Returns\Internal\ReturnRows;
use Commerz\Time\Contracts\Clock;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Orchestrates `OrderExchange` (inbound return + outbound replacement fulfillment with net delta) and `OrderClaim`
 * (free replacement/reshipment with zero invoice) in the sales lock hierarchy:
 * `receipt -> order FOR UPDATE -> return / fulfillment -> stock levels -> journal -> outbox`.
 */
#[Internal]
final readonly class DatabaseExchanges implements Exchanges
{
    private const string EXCHANGE_SCOPE = 'sales.create_exchange';

    private const string CLAIM_SCOPE = 'sales.create_claim';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private OrderLocks $locks,
        private Orders $orders,
        private Returns $returns,
        private ReturnRows $returnRows,
        private FulfillmentRows $fulfillmentRows,
        private ExchangeRows $rows,
        private Sellables $sellables,
        private QuotePipeline $quotes,
        private StockReservations $stock,
        private OrderJournal $journal,
        private Outbox $outbox,
        private CommittedEvents $events,
        private Clock $clock,
    ) {}

    public function createExchange(CreateOrderExchange $command): OrderExchange
    {
        $orderPreview = $this->orders->find($command->order);
        $context = new CommerceContext(
            $orderPreview->channel,
            $orderPreview->currency,
            $orderPreview->locale,
            $orderPreview->customer,
            $orderPreview->customerGroups,
            $this->clock->now(),
        );

        $outboundSnapshots = $this->sellables->resolveMany(
            array_map(static fn (ExchangeOutboundItem $item): SellableRef => $item->sellable, $command->outboundItems),
            $context,
        );

        $quoteLines = [];
        foreach ($command->outboundItems as $item) {
            $snapshot = $outboundSnapshots[(string) $item->sellable];

            if (! $snapshot->purchasable) {
                throw new SellableNotPurchasable($snapshot->ref, $snapshot->unavailableReason);
            }

            $quoteLines[] = new QuoteLineInput(
                lineId: Uuid7::generate(),
                sellable: $snapshot,
                quantity: $item->quantity,
                fixedUnitPrice: $item->fixedUnitPrice,
                taxIncluded: $item->taxIncluded,
                priceSource: $item->fixedUnitPrice !== null ? 'exchange_override' : null,
            );
        }

        $outboundQuote = $this->quotes->run(new QuoteInput($context, $quoteLines));

        return $this->transactions->run(function () use ($command, $outboundQuote): OrderExchange {
            if ($command->idempotencyKey === null) {
                return $this->createExchangeLocked($command, $outboundQuote);
            }

            $inboundFp = array_map(
                static fn (LineQuantity $line): array => [$line->lineId->value, $line->quantity],
                self::byLine($command->inboundLines),
            );
            $outboundFp = array_map(
                static fn (ExchangeOutboundItem $item): array => [
                    (string) $item->sellable,
                    $item->quantity,
                    $item->fixedUnitPrice?->amountMinor,
                    $item->fixedUnitPrice?->currency->code,
                    $item->taxIncluded,
                ],
                $command->outboundItems,
            );

            return $this->receipts->once(
                self::EXCHANGE_SCOPE,
                $command->idempotencyKey,
                Fingerprint::of([
                    'order' => $command->order->value,
                    'inbound' => $inboundFp,
                    'outbound' => $outboundFp,
                    'reason' => $command->reason?->value,
                    'note' => $command->note,
                ]),
                fn (): OrderExchange => $this->createExchangeLocked($command, $outboundQuote),
                static fn (OrderExchange $view): array => ['exchange_id' => $view->id->value],
                fn (array $stored): OrderExchange => $this->rows->getExchange(
                    is_string($stored['exchange_id'] ?? null)
                        ? $stored['exchange_id']
                        : throw new LogicException('An exchange receipt holds no exchange id.'),
                ),
            );
        }, lockTimeoutMs: 3000);
    }

    public function completeExchange(CompleteOrderExchange $command): OrderExchange
    {
        $orderId = $this->rows->orderOfExchange($command->exchange);

        return $this->transactions->run(function () use ($command, $orderId): OrderExchange {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lockExchange($command->exchange);

            if ($current->status === ExchangeStatus::Completed) {
                return $current;
            }

            if ($current->status === ExchangeStatus::Cancelled) {
                throw new ExchangeNotOpen($current->id->value, $current->status->value);
            }

            $this->returns->receive(new ReceiveReturn(
                return: $current->return,
                locationCode: $command->returnLocationCode,
                actor: $command->actor,
                disposition: $command->returnDispositions === [] ? $command->disposition : null,
                reason: $command->reason ?? $current->reason,
                dispositions: $command->returnDispositions,
            ));

            $fulfillmentId = FulfillmentId::generate();
            $this->fulfillmentRows->insert($fulfillmentId, $order->id, $command->fulfillmentLocationCode, []);

            foreach ($current->outboundLines as $outboundLine) {
                if ($outboundLine->kind === SellableKind::Physical) {
                    $this->stock->consume(new ConsumeReservation(
                        'exchange:'.$current->id->value,
                        $outboundLine->sellable,
                        $outboundLine->quantity,
                        'fulfillment:'.$fulfillmentId->value,
                        $command->fulfillmentLocationCode,
                    ));
                }
            }

            $this->rows->markExchangeCompleted($current->id, $fulfillmentId);

            $this->journal->append($order->id, 'exchange_completed', $command->actor, [
                'exchange_id' => $current->id->value,
                'return_id' => $current->return->value,
                'fulfillment_id' => $fulfillmentId->value,
                'disposition' => $command->disposition->value,
                'net_delta_minor' => $current->netDelta->amountMinor,
                'currency' => $current->netDelta->currency->code,
            ]);

            $this->outbox->record(IntegrationEvent::of('sales.exchange_completed', 'order:'.$order->id->value, [
                'order_id' => $order->id->value,
                'number' => $order->number,
                'exchange_id' => $current->id->value,
                'return_id' => $current->return->value,
                'fulfillment_id' => $fulfillmentId->value,
                'settlement' => $current->settlement->value,
                'net_delta_minor' => $current->netDelta->amountMinor,
                'amount_due_minor' => $current->amountDue->amountMinor,
                'refund_due_minor' => $current->refundDue->amountMinor,
                'currency' => $current->netDelta->currency->code,
            ]));

            $this->events->dispatch(new FulfillmentCreated($fulfillmentId, $order->id));
            $this->events->dispatch(new OrderExchangeCompleted(
                $current->id,
                $order->id,
                $current->return,
                $fulfillmentId,
                $current->netDelta,
            ));

            return $this->rows->getExchange($current->id);
        }, lockTimeoutMs: 3000);
    }

    public function cancelExchange(CancelOrderExchange $command): OrderExchange
    {
        $orderId = $this->rows->orderOfExchange($command->exchange);

        return $this->transactions->run(function () use ($command, $orderId): OrderExchange {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lockExchange($command->exchange);

            if ($current->status === ExchangeStatus::Cancelled) {
                return $current;
            }

            if ($current->status === ExchangeStatus::Completed) {
                throw new ExchangeNotOpen($current->id->value, $current->status->value);
            }

            $this->returns->cancel(new CancelReturn($current->return, $command->actor));

            if (self::hasPhysicalExchangeLines($current->outboundLines)) {
                $this->stock->release(new ReleaseReservation('exchange:'.$current->id->value));
            }

            $this->rows->markExchangeCancelled($current->id);
            $this->journal->append($order->id, 'exchange_cancelled', $command->actor, [
                'exchange_id' => $current->id->value,
                'return_id' => $current->return->value,
            ]);

            return $this->rows->getExchange($current->id);
        }, lockTimeoutMs: 3000);
    }

    public function findExchange(ExchangeId $id): OrderExchange
    {
        return $this->rows->getExchange($id);
    }

    public function listExchanges(ListOrderExchanges $query): Page
    {
        return $this->rows->pageExchanges($query);
    }

    public function createClaim(CreateOrderClaim $command): OrderClaim
    {
        $orderPreview = $this->orders->find($command->order);
        $context = new CommerceContext(
            $orderPreview->channel,
            $orderPreview->currency,
            $orderPreview->locale,
            $orderPreview->customer,
            $orderPreview->customerGroups,
            $this->clock->now(),
        );

        $explicitRefs = [];
        foreach ($command->items as $item) {
            if ($item->target instanceof SellableRef) {
                $explicitRefs[] = $item->target;
            }
        }

        $resolvedSnapshots = $explicitRefs === [] ? [] : $this->sellables->resolveMany($explicitRefs, $context);

        foreach ($resolvedSnapshots as $snapshot) {
            if (! $snapshot->purchasable) {
                throw new SellableNotPurchasable($snapshot->ref, $snapshot->unavailableReason);
            }
        }

        return $this->transactions->run(function () use ($command, $resolvedSnapshots): OrderClaim {
            if ($command->idempotencyKey === null) {
                return $this->createClaimLocked($command, $resolvedSnapshots);
            }

            $itemsFp = array_map(
                static fn (ClaimItem $item): array => [
                    $item->target instanceof OrderLineId ? 'line:'.$item->target->value : 'sellable:'.(string) $item->target,
                    $item->quantity,
                ],
                $command->items,
            );

            return $this->receipts->once(
                self::CLAIM_SCOPE,
                $command->idempotencyKey,
                Fingerprint::of([
                    'order' => $command->order->value,
                    'kind' => $command->kind->value,
                    'items' => $itemsFp,
                    'reason' => $command->reason?->value,
                    'note' => $command->note,
                    'fulfill_immediately' => $command->fulfillImmediately,
                    'location' => $command->locationCode,
                ]),
                fn (): OrderClaim => $this->createClaimLocked($command, $resolvedSnapshots),
                static fn (OrderClaim $view): array => ['claim_id' => $view->id->value],
                fn (array $stored): OrderClaim => $this->rows->getClaim(
                    is_string($stored['claim_id'] ?? null)
                        ? $stored['claim_id']
                        : throw new LogicException('A claim receipt holds no claim id.'),
                ),
            );
        }, lockTimeoutMs: 3000);
    }

    public function fulfillClaim(FulfillOrderClaim $command): OrderClaim
    {
        $orderId = $this->rows->orderOfClaim($command->claim);

        return $this->transactions->run(function () use ($command, $orderId): OrderClaim {
            $lockedOrder = $this->locks->lock($orderId);
            $current = $this->rows->lockClaim($command->claim);

            if ($current->status === ClaimStatus::Fulfilled) {
                return $current;
            }

            if ($current->status === ClaimStatus::Cancelled) {
                throw new ClaimNotOpen($current->id->value, $current->status->value);
            }

            return $this->fulfillClaimLocked($lockedOrder->id, $lockedOrder->number, $current, $command->locationCode, $command->actor);
        }, lockTimeoutMs: 3000);
    }

    public function cancelClaim(CancelOrderClaim $command): OrderClaim
    {
        $orderId = $this->rows->orderOfClaim($command->claim);

        return $this->transactions->run(function () use ($command, $orderId): OrderClaim {
            $order = $this->locks->lock($orderId);
            $current = $this->rows->lockClaim($command->claim);

            if ($current->status === ClaimStatus::Cancelled) {
                return $current;
            }

            if ($current->status === ClaimStatus::Fulfilled) {
                throw new ClaimNotOpen($current->id->value, $current->status->value);
            }

            if (self::hasPhysicalClaimLines($current->lines)) {
                $this->stock->release(new ReleaseReservation('claim:'.$current->id->value));
            }

            $this->rows->markClaimCancelled($current->id);
            $this->journal->append($order->id, 'claim_cancelled', $command->actor, ['claim_id' => $current->id->value]);

            return $this->rows->getClaim($current->id);
        }, lockTimeoutMs: 3000);
    }

    public function findClaim(ClaimId $id): OrderClaim
    {
        return $this->rows->getClaim($id);
    }

    public function listClaims(ListOrderClaims $query): Page
    {
        return $this->rows->pageClaims($query);
    }

    private function createExchangeLocked(CreateOrderExchange $command, QuoteResult $outboundQuote): OrderExchange
    {
        $lockedOrder = $this->locks->lock($command->order);
        $order = $this->orders->find($command->order);
        $currency = $order->currency;
        $zero = Money::zero($currency);

        $inboundLines = self::byLine($command->inboundLines);
        $openReturns = $this->returnRows->openQuantities($order->id);

        $inboundViews = [];
        $returnTotal = $zero;

        foreach ($inboundLines as $line) {
            $stored = $lockedOrder->line($line->lineId->value) ?? throw new LineNotFound($line->lineId->value);
            $orderLineView = $order->line($line->lineId) ?? throw new LineNotFound($line->lineId->value);

            $available = $lockedOrder->state === OrderState::Cancelled
                ? 0
                : max(0, $stored->fulfilled - $stored->returned - ($openReturns[$line->lineId->value] ?? 0));

            if ($line->quantity > $available) {
                throw new ReturnExceedsFulfilled($line->lineId->value, $line->quantity, $available);
            }

            $credit = $line->quantity === $orderLineView->quantity
                ? $orderLineView->total
                : MoneyAllocator::allocate($orderLineView->total, [$orderLineView->quantity - $line->quantity, $line->quantity])[1];

            $returnTotal = $returnTotal->plus($credit);
            $inboundViews[] = new ExchangeInboundLineView(
                $line->lineId,
                $orderLineView->sellable,
                $orderLineView->sku,
                $orderLineView->name,
                $line->quantity,
                $credit,
            );
        }

        $outboundViews = [];
        $reserveBySellable = [];
        $reserveRefs = [];

        foreach ($outboundQuote->lines as $quotedLine) {
            $lineTax = $zero;
            foreach ($quotedLine->taxLines as $tax) {
                $lineTax = $lineTax->plus($tax->amount);
            }

            $outboundViews[] = new ExchangeOutboundLineView(
                $quotedLine->lineId,
                $quotedLine->sellable->ref,
                $quotedLine->sellable->sku,
                $quotedLine->sellable->name,
                $quotedLine->sellable->kind,
                $quotedLine->sellable->requiresShipping,
                $quotedLine->sellable->weightGrams,
                $quotedLine->quantity,
                $quotedLine->unitPrice,
                $quotedLine->subtotal,
                $lineTax,
                $quotedLine->total,
            );

            if ($quotedLine->sellable->kind === SellableKind::Physical) {
                $refKey = (string) $quotedLine->sellable->ref;
                $reserveBySellable[$refKey] = ($reserveBySellable[$refKey] ?? 0) + $quotedLine->quantity;
                $reserveRefs[$refKey] = $quotedLine->sellable->ref;
            }
        }

        $outboundSubtotal = $outboundQuote->totals->subtotal->minus($outboundQuote->totals->discountTotal);
        $outboundTaxTotal = $outboundQuote->totals->taxTotal;
        $outboundTotal = $outboundQuote->totals->total;
        $netDelta = $outboundTotal->minus($returnTotal);

        [$settlement, $amountDue, $refundDue] = match (true) {
            $netDelta->isPositive() => [ExchangeSettlement::PaymentDue, $netDelta, $zero],
            $netDelta->isNegative() => [ExchangeSettlement::RefundDue, $zero, $netDelta->negate()],
            default => [ExchangeSettlement::Even, $zero, $zero],
        };

        $returnId = ReturnId::generate();
        $this->returnRows->insert(
            $returnId,
            $order->id,
            $command->note ?? $command->reason?->value,
            $inboundLines,
            $command->reason,
        );

        $exchangeId = ExchangeId::generate();

        if ($reserveBySellable !== []) {
            $reserveLines = array_map(
                static fn (SellableRef $sellable, int $qty): ReserveLine => new ReserveLine($sellable, $qty),
                array_values($reserveRefs),
                array_values($reserveBySellable),
            );
            $this->stock->reserve(new ReserveStock('exchange:'.$exchangeId->value, $order->channel, $reserveLines));
        }

        $this->rows->insertExchange(
            $exchangeId,
            $order->id,
            $returnId,
            $settlement,
            $command->reason,
            $command->note,
            $currency,
            $returnTotal,
            $outboundSubtotal,
            $outboundTaxTotal,
            $outboundTotal,
            $netDelta,
            $amountDue,
            $refundDue,
            $inboundViews,
            $outboundViews,
        );

        $this->journal->append($order->id, 'exchange_created', $command->actor, [
            'exchange_id' => $exchangeId->value,
            'return_id' => $returnId->value,
            'settlement' => $settlement->value,
            'return_total_minor' => $returnTotal->amountMinor,
            'outbound_total_minor' => $outboundTotal->amountMinor,
            'net_delta_minor' => $netDelta->amountMinor,
            'currency' => $currency->code,
        ]);

        $this->outbox->record(IntegrationEvent::of('sales.exchange_created', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'exchange_id' => $exchangeId->value,
            'return_id' => $returnId->value,
            'settlement' => $settlement->value,
            'return_total_minor' => $returnTotal->amountMinor,
            'outbound_total_minor' => $outboundTotal->amountMinor,
            'net_delta_minor' => $netDelta->amountMinor,
            'amount_due_minor' => $amountDue->amountMinor,
            'refund_due_minor' => $refundDue->amountMinor,
            'currency' => $currency->code,
        ]));

        $this->events->dispatch(new OrderExchangeCreated($exchangeId, $order->id, $returnId, $netDelta));

        return $this->rows->getExchange($exchangeId);
    }

    /**
     * @param  array<string, SellableSnapshot>  $resolvedSnapshots
     */
    private function createClaimLocked(CreateOrderClaim $command, array $resolvedSnapshots): OrderClaim
    {
        $lockedOrder = $this->locks->lock($command->order);
        $order = $this->orders->find($command->order);
        $currency = $order->currency;
        $zero = Money::zero($currency);

        if ($lockedOrder->state === OrderState::Cancelled) {
            throw new ReturnExceedsFulfilled($command->order->value, 1, 0);
        }

        $claimLines = [];
        $reserveBySellable = [];
        $reserveRefs = [];

        foreach ($command->items as $item) {
            if ($item->target instanceof OrderLineId) {
                $stored = $lockedOrder->line($item->target->value) ?? throw new LineNotFound($item->target->value);
                $lineView = $order->line($item->target) ?? throw new LineNotFound($item->target->value);

                if ($item->quantity > $stored->fulfilled) {
                    throw new ReturnExceedsFulfilled($item->target->value, $item->quantity, $stored->fulfilled);
                }

                $claimLines[] = new ClaimLineView(
                    Uuid7::generate(),
                    $item->target,
                    $lineView->sellable,
                    $lineView->sku,
                    $lineView->name,
                    $lineView->kind,
                    $lineView->requiresShipping,
                    $lineView->weightGrams,
                    $item->quantity,
                    $zero,
                    $zero,
                    $zero,
                    $zero,
                );

                if ($lineView->kind === SellableKind::Physical) {
                    $refKey = (string) $lineView->sellable;
                    $reserveBySellable[$refKey] = ($reserveBySellable[$refKey] ?? 0) + $item->quantity;
                    $reserveRefs[$refKey] = $lineView->sellable;
                }

                continue;
            }

            $snapshot = $resolvedSnapshots[(string) $item->target];
            $claimLines[] = new ClaimLineView(
                Uuid7::generate(),
                null,
                $snapshot->ref,
                $snapshot->sku,
                $snapshot->name,
                $snapshot->kind,
                $snapshot->requiresShipping,
                $snapshot->weightGrams,
                $item->quantity,
                $zero,
                $zero,
                $zero,
                $zero,
            );

            if ($snapshot->kind === SellableKind::Physical) {
                $refKey = (string) $snapshot->ref;
                $reserveBySellable[$refKey] = ($reserveBySellable[$refKey] ?? 0) + $item->quantity;
                $reserveRefs[$refKey] = $snapshot->ref;
            }
        }

        $claimId = ClaimId::generate();

        if ($reserveBySellable !== []) {
            $reserveLines = array_map(
                static fn (SellableRef $sellable, int $qty): ReserveLine => new ReserveLine($sellable, $qty),
                array_values($reserveRefs),
                array_values($reserveBySellable),
            );
            $this->stock->reserve(new ReserveStock('claim:'.$claimId->value, $order->channel, $reserveLines));
        }

        $this->rows->insertClaim(
            $claimId,
            $order->id,
            $command->kind,
            $command->reason,
            $command->note,
            $currency,
            $claimLines,
        );

        $this->journal->append($order->id, 'claim_created', $command->actor, [
            'claim_id' => $claimId->value,
            'kind' => $command->kind->value,
            'reason_code' => $command->reason?->value,
            'invoice_total_minor' => 0,
            'currency' => $currency->code,
        ]);

        $this->outbox->record(IntegrationEvent::of('sales.claim_created', 'order:'.$order->id->value, [
            'order_id' => $order->id->value,
            'number' => $order->number,
            'claim_id' => $claimId->value,
            'kind' => $command->kind->value,
            'invoice_total_minor' => 0,
            'amount_due_minor' => 0,
            'currency' => $currency->code,
        ]));

        $this->events->dispatch(new OrderClaimCreated($claimId, $order->id, $command->kind));

        $created = $this->rows->getClaim($claimId);

        if ($command->fulfillImmediately) {
            return $this->fulfillClaimLocked($order->id, $order->number, $created, $command->locationCode, $command->actor);
        }

        return $created;
    }

    private function fulfillClaimLocked(
        OrderId $orderId,
        string $orderNumber,
        OrderClaim $claim,
        ?string $locationCode,
        ?ActorRef $actor,
    ): OrderClaim {
        $fulfillmentId = FulfillmentId::generate();
        $this->fulfillmentRows->insert($fulfillmentId, $orderId, $locationCode, []);

        foreach ($claim->lines as $line) {
            if ($line->kind === SellableKind::Physical) {
                $this->stock->consume(new ConsumeReservation(
                    'claim:'.$claim->id->value,
                    $line->sellable,
                    $line->quantity,
                    'fulfillment:'.$fulfillmentId->value,
                    $locationCode,
                ));
            }
        }

        $this->rows->markClaimFulfilled($claim->id, $fulfillmentId);

        $this->journal->append($orderId, 'claim_fulfilled', $actor, [
            'claim_id' => $claim->id->value,
            'fulfillment_id' => $fulfillmentId->value,
            'invoice_total_minor' => 0,
            'currency' => $claim->invoiceTotal->currency->code,
        ]);

        $this->outbox->record(IntegrationEvent::of('sales.claim_fulfilled', 'order:'.$orderId->value, [
            'order_id' => $orderId->value,
            'number' => $orderNumber,
            'claim_id' => $claim->id->value,
            'fulfillment_id' => $fulfillmentId->value,
            'invoice_total_minor' => 0,
            'currency' => $claim->invoiceTotal->currency->code,
        ]));

        $this->events->dispatch(new FulfillmentCreated($fulfillmentId, $orderId));
        $this->events->dispatch(new OrderClaimFulfilled($claim->id, $orderId, $fulfillmentId));

        return $this->rows->getClaim($claim->id);
    }

    /**
     * @param  list<LineQuantity>  $lines
     * @return list<LineQuantity>
     */
    private static function byLine(array $lines): array
    {
        usort($lines, static fn (LineQuantity $a, LineQuantity $b): int => strcmp($a->lineId->value, $b->lineId->value));

        return $lines;
    }

    /**
     * @param  list<ExchangeOutboundLineView>  $lines
     */
    private static function hasPhysicalExchangeLines(array $lines): bool
    {
        return array_any($lines, static fn (ExchangeOutboundLineView $line): bool => $line->kind === SellableKind::Physical);
    }

    /**
     * @param  list<ClaimLineView>  $lines
     */
    private static function hasPhysicalClaimLines(array $lines): bool
    {
        return array_any($lines, static fn (ClaimLineView $line): bool => $line->kind === SellableKind::Physical);
    }
}
