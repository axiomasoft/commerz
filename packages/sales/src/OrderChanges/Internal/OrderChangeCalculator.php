<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Operations\Fingerprint;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\OrderChanges\Data\AddLineAction;
use Commerz\Sales\OrderChanges\Data\ChangeLineQuantityAction;
use Commerz\Sales\OrderChanges\Data\ChangeShippingMethodAction;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionView;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Data\OrderChangePreview;
use Commerz\Sales\OrderChanges\Data\OrderExcerpt;
use Commerz\Sales\OrderChanges\Data\OrderExcerptLine;
use Commerz\Sales\OrderChanges\Data\OverrideUnitPriceAction;
use Commerz\Sales\OrderChanges\Data\RemoveLineAction;
use Commerz\Sales\OrderChanges\Data\ReplaceAdjustmentsAction;
use Commerz\Sales\OrderChanges\Enums\CompensationDirection;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeLineLocked;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Data\OrderView;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Throwable;

/**
 * Pure dry-run calculator for `OrderChange`: mutates an in-memory working projection of the order, preserves
 * snapshotted prices for untouched lines (ORD-02), prices newly added lines via `PriceResolver`, and executes the
 * registered `Delivery` (when shipping method changed), `Tax`, and `Totals` quote stages without touching the database.
 */
#[Internal]
final readonly class OrderChangeCalculator
{
    public function __construct(
        private Sellables $sellables,
        private PriceResolver $prices,
        private Contributions $contributions,
    ) {}

    /**
     * @param  list<OrderChangeActionView>  $actions
     */
    public function calculate(
        OrderChangeId $changeId,
        OrderView $order,
        array $actions,
        DateTimeImmutable $at,
    ): OrderChangeCalculation {
        $excerptBefore = OrderExcerpt::fromOrder($order);
        $context = new CommerceContext(
            $order->channel,
            $order->currency,
            $order->locale,
            $order->customer,
            $order->customerGroups,
            $at,
        );

        /** @var list<WorkingOrderLine> $workingLines */
        $workingLines = array_map(
            static fn (OrderLineView $line): WorkingOrderLine => new WorkingOrderLine(
                id: $line->id,
                sellable: self::snapshotFromOrderLine($line),
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                compareAt: $line->compareAt,
                taxIncluded: $line->taxIncluded,
                priceSource: $line->priceSource,
                ownAdjustments: array_values(array_filter(
                    $line->adjustments,
                    static fn (Adjustment $a): bool => $a->target === AdjustmentTarget::Line,
                )),
                cancelledQuantity: $line->cancelledQuantity,
                fulfilledQuantity: $line->fulfilledQuantity,
                returnedQuantity: $line->returnedQuantity,
                sellerRef: $line->sellerRef,
                isNew: false,
                needsPricing: false,
            ),
            $order->lines,
        );

        $orderAdjustments = $order->orderAdjustments;
        $deliveryAdjustments = $order->deliveryAdjustments;
        $delivery = $order->delivery;
        $deliveryMethodToPrice = null;

        // Batch-resolve all sellables referenced by AddLine actions before applying mutations.
        $decodedActions = array_map(
            static fn (OrderChangeActionView $view): array => [
                'view' => $view,
                'action' => OrderChangeCodec::actionFromPayload($view->type, $view->payload),
            ],
            $actions,
        );

        $addRefs = [];

        foreach ($decodedActions as $entry) {
            if ($entry['action'] instanceof AddLineAction) {
                $addRefs[(string) $entry['action']->sellable] = $entry['action']->sellable;
            }
        }

        $resolvedSellables = $addRefs === []
            ? []
            : $this->sellables->resolveMany(array_values($addRefs), $context);

        foreach ($decodedActions as $entry) {
            $view = $entry['view'];
            $action = $entry['action'];

            if ($action instanceof AddLineAction) {
                $snapshot = $resolvedSellables[(string) $action->sellable];

                if (! $snapshot->purchasable) {
                    throw new SellableNotPurchasable($snapshot->ref, $snapshot->unavailableReason);
                }

                if ($action->unitPriceOverride !== null && ! $action->unitPriceOverride->currency->equals($order->currency)) {
                    throw new InvalidCommand('unitPriceOverride', "must be in {$order->currency->code}", 'sales.currency_mismatch');
                }

                $workingLines[] = new WorkingOrderLine(
                    id: new OrderLineId($view->id->value),
                    sellable: $snapshot,
                    quantity: $action->quantity,
                    unitPrice: $action->unitPriceOverride,
                    compareAt: null,
                    taxIncluded: false,
                    priceSource: $action->unitPriceOverride !== null ? 'order_change.override' : 'snapshot',
                    ownAdjustments: [],
                    cancelledQuantity: 0,
                    fulfilledQuantity: 0,
                    returnedQuantity: 0,
                    sellerRef: $action->sellerRef ?? $snapshot->sellerRef,
                    isNew: true,
                    needsPricing: $action->unitPriceOverride === null,
                );

                continue;
            }

            if ($action instanceof ChangeLineQuantityAction) {
                $line = self::findWorkingLine($workingLines, $action->line);
                $locked = $line->fulfilledQuantity + $line->cancelledQuantity;

                if ($action->quantity < $locked) {
                    throw new OrderChangeLineLocked($action->line->value, $action->quantity, $locked);
                }

                $line->quantity = $action->quantity;

                continue;
            }

            if ($action instanceof RemoveLineAction) {
                $index = self::findWorkingLineIndex($workingLines, $action->line);
                $line = $workingLines[$index];

                if ($line->fulfilledQuantity > 0) {
                    throw new OrderChangeLineLocked($action->line->value, 0, $line->fulfilledQuantity);
                }

                array_splice($workingLines, $index, 1);

                continue;
            }

            if ($action instanceof OverrideUnitPriceAction) {
                $line = self::findWorkingLine($workingLines, $action->line);

                if (! $action->unitPrice->currency->equals($order->currency)) {
                    throw new InvalidCommand('unitPrice', "must be in {$order->currency->code}", 'sales.currency_mismatch');
                }

                $line->unitPrice = $action->unitPrice;
                $line->priceSource = 'order_change.override';
                $line->needsPricing = false;

                continue;
            }

            if ($action instanceof ChangeShippingMethodAction) {
                if ($action->methodCode === null) {
                    $delivery = null;
                    $deliveryMethodToPrice = null;
                    $deliveryAdjustments = [];
                } elseif ($action->chargeOverride !== null) {
                    if (! $action->chargeOverride->price->currency->equals($order->currency)) {
                        throw new InvalidCommand('chargeOverride', "must be in {$order->currency->code}", 'sales.currency_mismatch');
                    }

                    $delivery = $action->chargeOverride;
                    $deliveryMethodToPrice = null;
                } else {
                    if ($order->shippingAddress === null) {
                        throw new ShippingAddressRequired;
                    }

                    $delivery = null;
                    $deliveryMethodToPrice = $action->methodCode;
                }

                continue;
            }

            if ($action instanceof ReplaceAdjustmentsAction) {
                foreach ($workingLines as $workingLine) {
                    $workingLine->ownAdjustments = [];
                }

                $orderAdjustments = [];
                $deliveryAdjustments = [];

                foreach ($action->adjustments as $adjustment) {
                    if (! $adjustment->amount->currency->equals($order->currency)) {
                        throw new InvalidCommand('adjustments', "must be in {$order->currency->code}", 'sales.currency_mismatch');
                    }

                    if ($adjustment->target === AdjustmentTarget::Line) {
                        $lineId = (string) $adjustment->lineId;
                        $targetLine = self::findWorkingLine($workingLines, new OrderLineId($lineId));
                        $targetLine->ownAdjustments[] = $adjustment;
                    } elseif ($adjustment->target === AdjustmentTarget::Order) {
                        $orderAdjustments[] = $adjustment->lineId === null
                            ? $adjustment
                            : Adjustment::order($adjustment->amount, $adjustment->source, $adjustment->explanation, $adjustment->promotionId, $adjustment->couponCode);
                    } else {
                        $deliveryAdjustments[] = $adjustment;
                    }
                }
            }
        }

        if ($workingLines === []) {
            throw new InvalidCommand('actions', 'an order must retain at least one line; cancel the order instead', 'sales.invalid_order_change');
        }

        // Price newly added lines that did not specify a manual unit price override.
        /** @var list<WorkingOrderLine> $toPrice */
        $toPrice = [];
        $priceRequests = [];

        foreach ($workingLines as $line) {
            if (! $line->needsPricing) {
                continue;
            }

            $toPrice[] = $line;
            $priceRequests[] = new ResolvePrice(
                $line->sellable->ref,
                $line->quantity,
                $line->sellable->basePrice ?? $line->sellable->unitPrice?->amount,
            );
        }

        if ($priceRequests !== []) {
            $resolvedPrices = $this->prices->resolveMany($priceRequests, $context);

            if (count($resolvedPrices) !== count($priceRequests)) {
                throw new LogicException('PriceResolver returned '.count($resolvedPrices).' prices for '.count($priceRequests).' requests.');
            }

            foreach ($toPrice as $offset => $line) {
                $resolved = $resolvedPrices[$offset];
                $line->unitPrice = $resolved->unit;
                $line->compareAt = $resolved->compareAt;
                $line->taxIncluded = $resolved->taxIncluded;
                $line->priceSource = $resolved->source;
                $line->needsPricing = false;
            }
        }

        // Build QuoteInput & QuoteState so real Delivery, Tax, and Totals stages compute exact figures.
        $quoteLineInputs = array_map(
            static fn (WorkingOrderLine $line): QuoteLineInput => new QuoteLineInput(
                $line->id->value,
                $line->sellable,
                $line->quantity,
            ),
            $workingLines,
        );

        $shippingWeight = 0;
        $hasShippingLines = false;

        foreach ($workingLines as $line) {
            if ($line->sellable->requiresShipping) {
                $hasShippingLines = true;
                $shippingWeight += ($line->sellable->weightGrams ?? 0) * $line->quantity;
            }
        }

        $deliverySelection = null;

        if ($deliveryMethodToPrice !== null) {
            $address = $order->shippingAddress ?? throw new ShippingAddressRequired;
            $deliverySelection = new DeliverySelection(
                $deliveryMethodToPrice,
                $address,
                new Parcel($shippingWeight, Money::zero($order->currency)),
            );
        } elseif ($delivery !== null && $order->shippingAddress !== null) {
            $deliverySelection = new DeliverySelection(
                $delivery->methodCode,
                $order->shippingAddress,
                new Parcel($shippingWeight, Money::zero($order->currency)),
            );
        }

        $quoteInput = new QuoteInput($context, $quoteLineInputs, $order->codes, $deliverySelection);
        $state = QuoteState::start($quoteInput);

        foreach ($workingLines as $line) {
            $unitPrice = $line->unitPrice ?? throw new LogicException("Working line '{$line->id->value}' has no unit price.");

            $state = $state->withLine(new QuoteLine(
                $line->id->value,
                $line->sellable,
                $line->quantity,
                $unitPrice,
                $line->compareAt,
                $line->taxIncluded,
                $line->priceSource,
            ));
        }

        foreach ($workingLines as $line) {
            foreach ($line->ownAdjustments as $adjustment) {
                $state = $state->withAdjustment($adjustment);
            }
        }

        foreach ($orderAdjustments as $adjustment) {
            $state = $state->withAdjustment($adjustment);
        }

        if ($deliveryMethodToPrice === null && $delivery !== null) {
            $state = $state->withDelivery($delivery);
        }

        foreach ($deliveryAdjustments as $adjustment) {
            $state = $state->withAdjustment($adjustment);
        }

        $state = $this->runQuoteStages($state, $deliveryMethodToPrice !== null);

        if ($deliveryMethodToPrice !== null && $state->delivery === null && $hasShippingLines) {
            throw new QuoteFailed('delivery', 'method_unpriced', "no stage priced the selected delivery method '{$deliveryMethodToPrice}'");
        }

        $totals = $state->totals ?? throw new QuoteFailed('totals', 'totals_missing', 'the order change quote was not settled');

        /** @var array<string, WorkingOrderLine> $linesById */
        $linesById = [];

        foreach ($workingLines as $line) {
            $linesById[$line->id->value] = $line;
        }

        $afterLines = [];
        $cancelledValueMinor = 0;

        foreach ($state->settledLines as $settled) {
            $meta = $linesById[$settled->lineId];
            $cancelledValueMinor += OrderStatus::cancelledValue(
                $settled->total,
                $settled->quantity,
                $meta->cancelledQuantity,
            )->amountMinor;

            $afterLines[] = new OrderExcerptLine(
                id: new OrderLineId($settled->lineId),
                sellable: $settled->sellable->ref,
                sku: $settled->sellable->sku,
                name: $settled->sellable->name,
                kind: $settled->sellable->kind,
                requiresShipping: $settled->sellable->requiresShipping,
                options: $settled->sellable->options,
                taxClass: $settled->sellable->taxClass,
                weightGrams: $settled->sellable->weightGrams,
                quantity: $settled->quantity,
                unitPrice: $settled->unitPrice,
                compareAt: $settled->compareAt,
                taxIncluded: $settled->taxIncluded,
                priceSource: $settled->priceSource,
                subtotal: $settled->subtotal,
                adjustments: $settled->adjustments,
                taxLines: $settled->taxLines,
                total: $settled->total,
                cancelledQuantity: $meta->cancelledQuantity,
                fulfilledQuantity: $meta->fulfilledQuantity,
                returnedQuantity: $meta->returnedQuantity,
                sellerRef: $meta->sellerRef,
                isNew: $meta->isNew,
            );
        }

        $afterOrderAdjustments = array_values(array_filter(
            $state->adjustments,
            static fn (Adjustment $a): bool => $a->target === AdjustmentTarget::Order,
        ));
        $afterDeliveryAdjustments = array_values(array_filter(
            $state->adjustments,
            static fn (Adjustment $a): bool => $a->target === AdjustmentTarget::Delivery,
        ));
        $afterDeliveryTaxLines = array_values(array_filter(
            $state->taxLines,
            static fn (TaxLine $t): bool => $t->target === AdjustmentTarget::Delivery,
        ));

        $afterAmountDue = Money::ofMinor(max(0, $totals->total->amountMinor - $cancelledValueMinor), $order->currency);

        $excerptAfter = new OrderExcerpt(
            orderId: $order->id,
            orderVersion: $order->version + 1,
            currency: $order->currency,
            lines: $afterLines,
            orderAdjustments: $afterOrderAdjustments,
            delivery: $state->delivery,
            deliveryAdjustments: $afterDeliveryAdjustments,
            deliveryTaxLines: $afterDeliveryTaxLines,
            totals: $totals,
            amountDue: $afterAmountDue,
        );

        $subtotalDelta = $excerptAfter->totals->subtotal->minus($excerptBefore->totals->subtotal);
        $discountDelta = $excerptAfter->totals->discountTotal->minus($excerptBefore->totals->discountTotal);
        $deliveryDelta = $excerptAfter->totals->deliveryTotal->minus($excerptBefore->totals->deliveryTotal);
        $taxDelta = $excerptAfter->totals->taxTotal->minus($excerptBefore->totals->taxTotal);
        $amountDelta = $excerptAfter->amountDue->minus($excerptBefore->amountDue);

        $direction = match (true) {
            $amountDelta->isPositive() => CompensationDirection::ChargeAdditional,
            $amountDelta->isNegative() => CompensationDirection::RefundOrCredit,
            default => CompensationDirection::None,
        };

        $fingerprint = Fingerprint::of([
            'order_id' => $order->id->value,
            'order_version' => $order->version,
            'change_id' => $changeId->value,
            'actions' => array_map(static fn (OrderChangeActionView $a): array => [
                'id' => $a->id->value,
                'position' => $a->position,
                'type' => $a->type->value,
                'payload' => $a->payload,
            ], $actions),
            'total_minor' => $excerptAfter->totals->total->amountMinor,
            'tax_minor' => $excerptAfter->totals->taxTotal->amountMinor,
        ]);

        [$stockDeltas, $stockSellables] = self::computeStockDeltas($excerptBefore->lines, $excerptAfter->lines);

        return new OrderChangeCalculation(
            preview: new OrderChangePreview(
                changeId: $changeId,
                orderId: $order->id,
                orderVersion: $order->version,
                excerptBefore: $excerptBefore,
                excerptAfter: $excerptAfter,
                subtotalDelta: $subtotalDelta,
                discountDelta: $discountDelta,
                deliveryDelta: $deliveryDelta,
                taxDelta: $taxDelta,
                amountDelta: $amountDelta,
                compensationDirection: $direction,
                quoteFingerprint: $fingerprint,
                actions: $actions,
            ),
            stockDeltas: $stockDeltas,
            stockSellables: $stockSellables,
        );
    }

    private function runQuoteStages(QuoteState $state, bool $priceDelivery): QuoteState
    {
        $stages = array_map(
            fn (string $key): QuoteStage => $this->contributions->get(QuoteStage::KIND, $key, QuoteStage::class),
            $this->contributions->keys(QuoteStage::KIND),
        );

        usort($stages, static fn (QuoteStage $a, QuoteStage $b): int => [$a->anchor()->order(), $a->position()->order(), $a->key()]
            <=> [$b->anchor()->order(), $b->position()->order(), $b->key()]);

        $allowedAnchors = [QuoteAnchor::Tax, QuoteAnchor::Totals];

        if ($priceDelivery) {
            $allowedAnchors[] = QuoteAnchor::Delivery;
        }

        foreach ($stages as $stage) {
            if (! in_array($stage->anchor(), $allowedAnchors, true)) {
                continue;
            }

            $policy = $stage instanceof QuoteStagePolicy ? $stage->failurePolicy() : FailurePolicy::BlockOnFailure;
            $timeoutMs = $stage instanceof QuoteStagePolicy ? $stage->timeoutMilliseconds() : null;
            $startedAt = hrtime(true);

            try {
                $next = $stage->apply($state);
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                if ($timeoutMs !== null && $elapsedMs > $timeoutMs) {
                    throw QuoteFailed::timedOut($stage->key(), $timeoutMs);
                }

                $state = $next;
            } catch (QuoteFailed $failed) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($stage->key(), $failed->reason, $failed->getMessage()));

                    continue;
                }

                throw $failed;
            } catch (Throwable $error) {
                if ($policy === FailurePolicy::IgnoreOnFailure) {
                    $state = $state->withRejection(new Rejection($stage->key(), 'stage_error', $error->getMessage()));

                    continue;
                }

                throw QuoteFailed::byStage($stage->key(), $error);
            }
        }

        return $state;
    }

    /** @param  list<WorkingOrderLine>  $lines */
    private static function findWorkingLine(array $lines, OrderLineId $id): WorkingOrderLine
    {
        return $lines[self::findWorkingLineIndex($lines, $id)];
    }

    /** @param  list<WorkingOrderLine>  $lines */
    private static function findWorkingLineIndex(array $lines, OrderLineId $id): int
    {
        foreach ($lines as $index => $line) {
            if ($line->id->equals($id)) {
                return $index;
            }
        }

        throw new LineNotFound($id->value);
    }

    private static function snapshotFromOrderLine(OrderLineView $line): SellableSnapshot
    {
        return new SellableSnapshot(
            ref: $line->sellable,
            sku: $line->sku,
            name: $line->name,
            kind: $line->kind,
            requiresShipping: $line->requiresShipping,
            purchasable: true,
            unavailableReason: null,
            options: $line->options,
            basePrice: $line->unitPrice,
            taxClass: $line->taxClass,
            weightGrams: $line->weightGrams,
            sellerRef: $line->sellerRef,
        );
    }

    /**
     * Computes net outstanding physical quantity delta per sellable (after - before).
     *
     * @param  list<OrderExcerptLine>  $before
     * @param  list<OrderExcerptLine>  $after
     * @return array{array<string, int>, array<string, SellableRef>}
     */
    private static function computeStockDeltas(array $before, array $after): array
    {
        $deltas = [];
        $sellables = [];

        foreach ($before as $line) {
            if ($line->kind !== SellableKind::Physical) {
                continue;
            }

            $key = (string) $line->sellable;
            $outstanding = $line->quantity - $line->cancelledQuantity - $line->fulfilledQuantity;
            $deltas[$key] = ($deltas[$key] ?? 0) - $outstanding;
            $sellables[$key] = $line->sellable;
        }

        foreach ($after as $line) {
            if ($line->kind !== SellableKind::Physical) {
                continue;
            }

            $key = (string) $line->sellable;
            $outstanding = $line->quantity - $line->cancelledQuantity - $line->fulfilledQuantity;
            $deltas[$key] = ($deltas[$key] ?? 0) + $outstanding;
            $sellables[$key] = $line->sellable;
        }

        $nonZero = array_filter($deltas, static fn (int $delta): bool => $delta !== 0);

        return [$nonZero, $sellables];
    }
}
