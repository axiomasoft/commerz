<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvedPrice;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Ports\StoredValue\Data\TenderSettlement;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Internal\TotalsStage;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRows;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Common\Internal\OrderWriter;
use Commerz\Sales\Common\Internal\SalesCodec;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Commerz\Sales\Errors\SalesProblemDefinitions;
use Commerz\Sales\OrderChanges\Contracts\OrderChangeCompensator;
use Commerz\Sales\OrderChanges\Data\AddLineAction;
use Commerz\Sales\OrderChanges\Data\AddOrderChangeAction;
use Commerz\Sales\OrderChanges\Data\CancelOrderChange;
use Commerz\Sales\OrderChanges\Data\ChangeLineQuantityAction;
use Commerz\Sales\OrderChanges\Data\ChangeShippingMethodAction;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\CreateOrderChange;
use Commerz\Sales\OrderChanges\Data\OrderChangeAction;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionId;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionView;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Data\OverrideUnitPriceAction;
use Commerz\Sales\OrderChanges\Data\RemoveLineAction;
use Commerz\Sales\OrderChanges\Data\ReplaceAdjustmentsAction;
use Commerz\Sales\OrderChanges\Enums\CompensationDirection;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\OrderChanges\Enums\NegativeDeltaStrategy;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\OrderChanges\Enums\PositiveDeltaStrategy;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeLineLocked;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotOpen;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\OrderChanges\Internal\DatabaseOrderChanges;
use Commerz\Sales\OrderChanges\Internal\DefaultOrderChangeCompensator;
use Commerz\Sales\OrderChanges\Internal\OrderChangeCalculator;
use Commerz\Sales\OrderChanges\Internal\OrderChangeCodec;
use Commerz\Sales\OrderChanges\Internal\OrderChangeRows;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\PaymentEventResult;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\ReconcileRefund;
use Commerz\Sales\Refunds\Data\RecordRefundEvent;
use Commerz\Sales\Refunds\Data\RefundEventResult;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\StoreId;
use Commerz\Testkit\Fakes\FrozenClock;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;
use PHPUnit\Framework\Assert;

/**
 * Builds an OrderChangeCalculator wired with a 20% excluded VAT stage, a tiered shipping stage (`standard` 10.00,
 * `express` 25.00), and core `TotalsStage`.
 *
 * @param  array<string, SellableSnapshot>  $catalog
 * @param  array<string, int>  $priceMinorByRef
 */
function ocCalculator(array $catalog = [], array $priceMinorByRef = []): OrderChangeCalculator
{
    $sellables = new readonly class($catalog) implements Sellables
    {
        /** @param  array<string, SellableSnapshot>  $catalog */
        public function __construct(private array $catalog) {}

        public function resolve(SellableRef $ref, CommerceContext $context): SellableSnapshot
        {
            return $this->catalog[(string) $ref];
        }

        public function resolveMany(array $refs, CommerceContext $context): array
        {
            $result = [];

            foreach ($refs as $ref) {
                $key = (string) $ref;
                $result[$key] = $this->catalog[$key];
            }

            return $result;
        }
    };

    $prices = new readonly class($priceMinorByRef) implements PriceResolver
    {
        /** @param  array<string, int>  $priceMinorByRef */
        public function __construct(private array $priceMinorByRef) {}

        public function resolveMany(array $requests, CommerceContext $context): array
        {
            return array_map(
                fn (ResolvePrice $req): ResolvedPrice => new ResolvedPrice(
                    unit: isset($this->priceMinorByRef[(string) $req->sellable])
                        ? Money::ofMinor($this->priceMinorByRef[(string) $req->sellable], $context->currency)
                        : ($req->fallback ?? Money::zero($context->currency)),
                    compareAt: null,
                    taxIncluded: false,
                    source: 'test.price_list',
                ),
                $requests,
            );
        }
    };

    $deliveryStage = new readonly class implements QuoteStage
    {
        public function key(): string
        {
            return 'test.delivery';
        }

        public function anchor(): QuoteAnchor
        {
            return QuoteAnchor::Delivery;
        }

        public function position(): StagePosition
        {
            return StagePosition::At;
        }

        public function apply(QuoteState $state): QuoteState
        {
            $selection = $state->input->delivery;

            if ($selection === null) {
                return $state;
            }

            $price = match ($selection->methodCode) {
                'standard' => Money::of('10.00', $state->currency()),
                'express' => Money::of('25.00', $state->currency()),
                default => null,
            };

            return $price === null
                ? $state
                : $state->withDelivery(new DeliveryCharge($selection->methodCode, ucfirst($selection->methodCode), 'carrier', $price, false));
        }
    };

    // 20% excluded VAT on every line's post-discount net and on delivery net.
    $vatStage = new readonly class implements QuoteStage
    {
        public function key(): string
        {
            return 'test.vat20';
        }

        public function anchor(): QuoteAnchor
        {
            return QuoteAnchor::Tax;
        }

        public function position(): StagePosition
        {
            return StagePosition::At;
        }

        public function apply(QuoteState $state): QuoteState
        {
            $rate = Rate::bp(2000);
            $shares = [];

            foreach ($state->allocatedOrderAdjustments() as $share) {
                $id = (string) $share->lineId;
                $shares[$id] = isset($shares[$id]) ? $shares[$id]->plus($share->amount) : $share->amount;
            }

            foreach ($state->lines as $line) {
                $base = $state->lineNet($line->lineId);

                if (isset($shares[$line->lineId])) {
                    $base = $base->plus($shares[$line->lineId]);
                }

                $tax = $base->percentage($rate, RoundingMode::HalfUp);
                $state = $state->withTaxLine(new TaxLine(AdjustmentTarget::Line, $line->lineId, 'vat', $rate, $tax, false));
            }

            $deliveryNet = $state->deliveryNet();

            if ($state->delivery !== null && $deliveryNet !== null) {
                $tax = $deliveryNet->percentage($rate, RoundingMode::HalfUp);
                $state = $state->withTaxLine(new TaxLine(AdjustmentTarget::Delivery, null, 'vat', $rate, $tax, false));
            }

            return $state;
        }
    };

    $totalsStage = new TotalsStage;
    $stages = [
        'test.delivery' => $deliveryStage,
        'test.vat20' => $vatStage,
        'totals' => $totalsStage,
    ];

    $contributions = new readonly class($stages) implements Contributions
    {
        /** @param  array<string, QuoteStage>  $stages */
        public function __construct(private array $stages) {}

        public function has(string $kind, string $key): bool
        {
            return $kind === QuoteStage::KIND && isset($this->stages[$key]);
        }

        /**
         * @template T of object
         *
         * @param  class-string<T>  $expected
         * @return T
         */
        public function get(string $kind, string $key, string $expected): object
        {
            $stage = $this->stages[$key] ?? throw new RuntimeException("Unknown stage {$key}.");

            if (! $stage instanceof $expected) {
                throw new RuntimeException("Stage {$key} is not {$expected}.");
            }

            return $stage;
        }

        /** @return list<string> */
        public function keys(string $kind): array
        {
            return $kind === QuoteStage::KIND ? array_keys($this->stages) : [];
        }

        /** @return list<QuoteStage> */
        public function all(string $kind, string $expected): array
        {
            return $kind === QuoteStage::KIND ? array_values($this->stages) : [];
        }
    };

    return new OrderChangeCalculator($sellables, $prices, $contributions);
}

/**
 * Creates a placed OrderView with two lines (Line 1: 2 x 100.00 + 20% VAT = 240.00; Line 2: 1 x 50.00 + 20% VAT = 60.00)
 * and standard shipping (10.00 + 20% VAT = 12.00) -> subtotal 250.00, delivery 10.00, tax 52.00, total 312.00.
 */
function ocSampleOrder(int $version = 1, int $line1Fulfilled = 0, int $capturedMinor = 0, int $authorizedMinor = 0): OrderView
{
    $currency = Currency::of('RUB');
    $now = new DateTimeImmutable('2026-11-26T12:00:00+00:00');
    $line1Id = new OrderLineId('019a0000-0000-7000-8000-000000000001');
    $line2Id = new OrderLineId('019a0000-0000-7000-8000-000000000002');
    $rate = Rate::bp(2000);

    $line1 = new OrderLineView(
        id: $line1Id,
        sellable: SellableRef::of('variant', 'v1'),
        sku: 'SKU-1',
        name: 'Shirt',
        kind: SellableKind::Physical,
        requiresShipping: true,
        options: ['Size' => 'M'],
        taxClass: 'standard',
        weightGrams: 300,
        quantity: 2,
        unitPrice: Money::of('100.00', $currency),
        compareAt: null,
        taxIncluded: false,
        priceSource: 'snapshot',
        subtotal: Money::of('200.00', $currency),
        adjustments: [],
        taxLines: [new TaxLine(AdjustmentTarget::Line, $line1Id->value, 'vat', $rate, Money::of('40.00', $currency), false)],
        total: Money::of('240.00', $currency),
        cancelledQuantity: 0,
        fulfilledQuantity: $line1Fulfilled,
        returnedQuantity: 0,
    );

    $line2 = new OrderLineView(
        id: $line2Id,
        sellable: SellableRef::of('variant', 'v2'),
        sku: 'SKU-2',
        name: 'Cap',
        kind: SellableKind::Physical,
        requiresShipping: true,
        options: [],
        taxClass: 'standard',
        weightGrams: 150,
        quantity: 1,
        unitPrice: Money::of('50.00', $currency),
        compareAt: null,
        taxIncluded: false,
        priceSource: 'snapshot',
        subtotal: Money::of('50.00', $currency),
        adjustments: [],
        taxLines: [new TaxLine(AdjustmentTarget::Line, $line2Id->value, 'vat', $rate, Money::of('10.00', $currency), false)],
        total: Money::of('60.00', $currency),
        cancelledQuantity: 0,
        fulfilledQuantity: 0,
        returnedQuantity: 0,
    );

    return new OrderView(
        id: new OrderId('019a0000-0000-7000-8000-000000000010'),
        number: 'ORD-1001',
        store: new StoreId('019a0000-0000-7000-8000-000000000020'),
        channel: new ChannelId('019a0000-0000-7000-8000-000000000021'),
        cart: new CartId('019a0000-0000-7000-8000-000000000022'),
        origin: CartKind::Cart,
        state: OrderState::Placed,
        paymentState: $capturedMinor >= 31200 ? PaymentState::Paid : ($authorizedMinor > 0 ? PaymentState::Authorized : PaymentState::Unpaid),
        fulfillmentState: $line1Fulfilled > 0 ? FulfillmentState::PartiallyFulfilled : FulfillmentState::Unfulfilled,
        cancelReason: null,
        currency: $currency,
        locale: 'ru',
        customer: CustomerRef::of('user', '42'),
        customerGroups: [],
        customerEmail: 'buyer@example.test',
        customerName: 'Buyer',
        lines: [$line1, $line2],
        orderAdjustments: [],
        delivery: new DeliveryCharge('standard', 'Standard', 'carrier', Money::of('10.00', $currency), false),
        deliveryAdjustments: [],
        deliveryTaxLines: [new TaxLine(AdjustmentTarget::Delivery, null, 'vat', $rate, Money::of('2.00', $currency), false)],
        totals: new QuoteTotals(
            subtotal: Money::of('250.00', $currency),
            discountTotal: Money::zero($currency),
            deliveryTotal: Money::of('10.00', $currency),
            taxTotal: Money::of('52.00', $currency),
            total: Money::of('312.00', $currency),
        ),
        amountDue: Money::of('312.00', $currency),
        authorized: Money::ofMinor($authorizedMinor, $currency),
        captured: Money::ofMinor($capturedMinor, $currency),
        refunded: Money::zero($currency),
        codes: [],
        shippingAddress: new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Buyer', '+70000000000'),
        billingAddress: null,
        quoteFingerprint: str_repeat('a', 64),
        paymentDueAt: null,
        placedAt: $now,
        completedAt: null,
        cancelledAt: null,
        version: $version,
    );
}

function ocActionView(int $position, OrderChangeAction $action): OrderChangeActionView
{
    return new OrderChangeActionView(
        id: OrderChangeActionId::generate(),
        position: $position,
        type: $action->type(),
        payload: $action->toPayload(),
        createdAt: new DateTimeImmutable('2026-11-26T12:05:00+00:00'),
    );
}

it('round-trips all 6 order change action payloads through OrderChangeCodec', function (): void {
    $lineId = new OrderLineId('019a0000-0000-7000-8000-000000000001');
    $actions = [
        new AddLineAction(SellableRef::of('variant', 'v3'), 2, Money::of('45.00', 'RUB')),
        new ChangeLineQuantityAction($lineId, 4),
        new RemoveLineAction($lineId),
        new OverrideUnitPriceAction($lineId, Money::of('79.90', 'RUB'), 'VIP match'),
        new ChangeShippingMethodAction('express', new DeliveryCharge('express', 'Express', 'dhl', Money::of('25.00', 'RUB'), false)),
        new ReplaceAdjustmentsAction([
            Adjustment::line($lineId->value, Money::of('-5.00', 'RUB'), 'manual', 'Goodwill'),
            Adjustment::order(Money::of('-10.00', 'RUB'), 'manual', 'Order discount'),
        ]),
    ];

    foreach ($actions as $action) {
        $decoded = OrderChangeCodec::actionFromPayload($action->type(), $action->toPayload());
        expect($decoded)->toEqual($action);
    }
});

it('calculates dry-run preview for AddLine and RemoveLine with exact tax delta, amountDelta, and stock deltas', function (): void {
    $order = ocSampleOrder();
    $v3 = new SellableSnapshot(
        ref: SellableRef::of('variant', 'v3'),
        sku: 'SKU-3',
        name: 'Jacket',
        kind: SellableKind::Physical,
        requiresShipping: true,
        basePrice: Money::of('150.00', 'RUB'),
        taxClass: 'standard',
        weightGrams: 800,
    );

    // PriceResolver returns 150.00 for v3 (and would return 999.00 for v1, proving ORD-02 preserves v1 snapshot 100.00).
    $calculator = ocCalculator(
        ['variant:v3' => $v3],
        ['variant:v1' => 99900, 'variant:v3' => 15000],
    );

    $addPreview = $calculator->calculate(
        OrderChangeId::generate(),
        $order,
        [ocActionView(1, new AddLineAction(SellableRef::of('variant', 'v3'), 1))],
        new DateTimeImmutable('2026-11-26T12:10:00+00:00'),
    );

    // Before: subtotal 250, delivery 10, tax 52, total 312.
    // After adding 1 x 150.00 (+30.00 VAT = +180.00): subtotal 400, delivery 10, tax 82, total 492.
    expect($addPreview->preview->excerptBefore->totals->total)->toEqual(Money::of('312.00', 'RUB'))
        ->and($addPreview->preview->excerptAfter->totals->subtotal)->toEqual(Money::of('400.00', 'RUB'))
        ->and($addPreview->preview->excerptAfter->totals->taxTotal)->toEqual(Money::of('82.00', 'RUB'))
        ->and($addPreview->preview->excerptAfter->totals->total)->toEqual(Money::of('492.00', 'RUB'))
        ->and($addPreview->preview->subtotalDelta)->toEqual(Money::of('150.00', 'RUB'))
        ->and($addPreview->preview->taxDelta)->toEqual(Money::of('30.00', 'RUB'))
        ->and($addPreview->preview->amountDelta)->toEqual(Money::of('180.00', 'RUB'))
        ->and($addPreview->preview->compensationDirection)->toBe(CompensationDirection::ChargeAdditional)
        ->and($addPreview->preview->excerptAfter->lines[0]->unitPrice)->toEqual(Money::of('100.00', 'RUB')) // ORD-02 preserved
        ->and($addPreview->stockDeltas)->toBe(['variant:v3' => 1]);

    // Remove line 2 (1 x 50.00 + 10.00 VAT = -60.00) -> subtotal 200, delivery 10, tax 42, total 252.
    $removePreview = $calculator->calculate(
        OrderChangeId::generate(),
        $order,
        [ocActionView(1, new RemoveLineAction($order->lines[1]->id))],
        new DateTimeImmutable('2026-11-26T12:10:00+00:00'),
    );

    expect($removePreview->preview->excerptAfter->lines)->toHaveCount(1)
        ->and($removePreview->preview->excerptAfter->totals->subtotal)->toEqual(Money::of('200.00', 'RUB'))
        ->and($removePreview->preview->excerptAfter->totals->taxTotal)->toEqual(Money::of('42.00', 'RUB'))
        ->and($removePreview->preview->excerptAfter->totals->total)->toEqual(Money::of('252.00', 'RUB'))
        ->and($removePreview->preview->taxDelta)->toEqual(Money::of('-10.00', 'RUB'))
        ->and($removePreview->preview->amountDelta)->toEqual(Money::of('-60.00', 'RUB'))
        ->and($removePreview->preview->compensationDirection)->toBe(CompensationDirection::RefundOrCredit)
        ->and($removePreview->stockDeltas)->toBe(['variant:v2' => -1]);
});

it('supports ChangeLineQuantity, OverrideUnitPrice, ChangeShippingMethod, and ReplaceAdjustments with exact reconciliation', function (): void {
    $order = ocSampleOrder();
    $calculator = ocCalculator();

    $calc = $calculator->calculate(
        OrderChangeId::generate(),
        $order,
        [
            ocActionView(1, new ChangeLineQuantityAction($order->lines[0]->id, 3)), // line1: 3 x 100 = 300
            ocActionView(2, new OverrideUnitPriceAction($order->lines[1]->id, Money::of('40.00', 'RUB'), 'Price match')), // line2: 1 x 40 = 40
            ocActionView(3, new ChangeShippingMethodAction('express')), // delivery: 25.00 (+5.00 VAT)
            ocActionView(4, new ReplaceAdjustmentsAction([
                Adjustment::order(Money::of('-40.00', 'RUB'), 'order_change.manual', 'Loyalty adjustment'),
            ])),
        ],
        new DateTimeImmutable('2026-11-26T12:15:00+00:00'),
    );

    // Subtotal: 300 + 40 = 340.00
    // Discount: 40.00 -> net goods = 300.00 -> 20% VAT on goods = 60.00
    // Delivery: 25.00 -> 20% VAT on delivery = 5.00
    // Total tax = 65.00 (delta = 65 - 52 = +13.00)
    // Total = 340 - 40 + 25 + 65 = 390.00 (delta = 390 - 312 = +78.00)
    expect($calc->preview->excerptAfter->totals->subtotal)->toEqual(Money::of('340.00', 'RUB'))
        ->and($calc->preview->excerptAfter->totals->discountTotal)->toEqual(Money::of('40.00', 'RUB'))
        ->and($calc->preview->excerptAfter->totals->deliveryTotal)->toEqual(Money::of('25.00', 'RUB'))
        ->and($calc->preview->excerptAfter->totals->taxTotal)->toEqual(Money::of('65.00', 'RUB'))
        ->and($calc->preview->excerptAfter->totals->total)->toEqual(Money::of('390.00', 'RUB'))
        ->and($calc->preview->deliveryDelta)->toEqual(Money::of('15.00', 'RUB'))
        ->and($calc->preview->taxDelta)->toEqual(Money::of('13.00', 'RUB'))
        ->and($calc->preview->amountDelta)->toEqual(Money::of('78.00', 'RUB'))
        ->and($calc->stockDeltas)->toBe(['variant:v1' => 1]);
});

it('rejects reducing quantity below fulfilled units, removing fulfilled lines, or removing the last line', function (): void {
    $order = ocSampleOrder(line1Fulfilled: 2);
    $calculator = ocCalculator();

    expect(fn () => $calculator->calculate(
        OrderChangeId::generate(),
        $order,
        [ocActionView(1, new ChangeLineQuantityAction($order->lines[0]->id, 1))],
        new DateTimeImmutable('2026-11-26T12:15:00+00:00'),
    ))->toThrow(OrderChangeLineLocked::class);

    expect(fn () => $calculator->calculate(
        OrderChangeId::generate(),
        $order,
        [ocActionView(1, new RemoveLineAction($order->lines[0]->id))],
        new DateTimeImmutable('2026-11-26T12:15:00+00:00'),
    ))->toThrow(OrderChangeLineLocked::class);

    $unfulfilledOrder = ocSampleOrder();

    expect(fn () => $calculator->calculate(
        OrderChangeId::generate(),
        $unfulfilledOrder,
        [
            ocActionView(1, new RemoveLineAction($unfulfilledOrder->lines[0]->id)),
            ocActionView(2, new RemoveLineAction($unfulfilledOrder->lines[1]->id)),
        ],
        new DateTimeImmutable('2026-11-26T12:15:00+00:00'),
    ))->toThrow(InvalidCommand::class, 'retain at least one line');
});

it('runs compensating workflows for positive deltas (IncrementAuthorization & DeltaCharge) and negative deltas (PartialRefund & WalletCredit)', function (): void {
    $authPaymentId = PaymentId::generate();
    $capturedPaymentId = PaymentId::generate();
    $now = new DateTimeImmutable('2026-11-26T12:00:00+00:00');
    $order = ocSampleOrder(capturedMinor: 31200, authorizedMinor: 31200);

    $initiatedPayments = [];
    $issuedRefunds = [];
    $walletCredits = [];

    $paymentsFake = new class($authPaymentId, $order, $now, $initiatedPayments) implements Payments
    {
        /** @param  list<InitiatePayment>  $initiated */
        public function __construct(
            private PaymentId $authPaymentId,
            private OrderView $order,
            private DateTimeImmutable $now,
            public array &$initiated,
        ) {}

        public function initiate(InitiatePayment $command): PaymentView
        {
            $this->initiated[] = $command;

            return new PaymentView(
                id: PaymentId::generate(),
                order: $command->order,
                gateway: $command->gateway,
                reference: 'payment:delta',
                externalId: 'ext-delta',
                status: PaymentStatus::Authorized,
                amount: $command->amount ?? Money::zero('RUB'),
                authorized: $command->amount ?? Money::zero('RUB'),
                captured: Money::zero('RUB'),
                refunded: Money::zero('RUB'),
                actionUrl: null,
                meta: $command->meta,
                attempts: 1,
                errorCode: null,
                errorMessage: null,
                createdAt: $this->now,
                updatedAt: $this->now,
            );
        }

        public function recordEvent(RecordPaymentEvent $command): PaymentEventResult
        {
            throw new LogicException('Unused');
        }

        public function reconcile(ReconcilePayment $command): PaymentView
        {
            throw new LogicException('Unused');
        }

        public function find(PaymentId $id): PaymentView
        {
            throw new LogicException('Unused');
        }

        public function list(ListPayments $query): Page
        {
            return new Page([
                new PaymentView(
                    id: $this->authPaymentId,
                    order: $this->order->id,
                    gateway: 'stripe',
                    reference: 'payment:orig',
                    externalId: 'pi_123',
                    status: PaymentStatus::Authorized,
                    amount: Money::of('312.00', 'RUB'),
                    authorized: Money::of('312.00', 'RUB'),
                    captured: Money::zero('RUB'),
                    refunded: Money::zero('RUB'),
                    actionUrl: null,
                    meta: [],
                    attempts: 1,
                    errorCode: null,
                    errorMessage: null,
                    createdAt: $this->now,
                    updatedAt: $this->now,
                ),
            ]);
        }
    };

    $refundsFake = new class($capturedPaymentId, $now, $issuedRefunds) implements Refunds
    {
        /** @param  list<IssueRefund>  $issued */
        public function __construct(
            private PaymentId $paymentId,
            private DateTimeImmutable $now,
            public array &$issued,
        ) {}

        public function issue(IssueRefund $command): RefundView
        {
            $this->issued[] = $command;

            return new RefundView(
                id: RefundId::generate(),
                order: $command->order,
                payment: $command->payment ?? $this->paymentId,
                return: $command->return,
                gateway: 'stripe',
                reference: 'refund:delta',
                externalId: 're_123',
                status: RefundStatus::Succeeded,
                amount: $command->amount,
                reason: $command->reason,
                attempts: 1,
                errorCode: null,
                errorMessage: null,
                createdAt: $this->now,
                updatedAt: $this->now,
            );
        }

        public function recordEvent(RecordRefundEvent $command): RefundEventResult
        {
            throw new LogicException('Unused');
        }

        public function reconcile(ReconcileRefund $command): RefundView
        {
            throw new LogicException('Unused');
        }

        public function find(RefundId $id): RefundView
        {
            throw new LogicException('Unused');
        }

        public function list(ListRefunds $query): Page
        {
            return new Page([]);
        }
    };

    $storedValueFake = new class($walletCredits) implements StoredValueTender
    {
        /** @param  list<RefundToStoredValue>  $credits */
        public function __construct(public array &$credits) {}

        public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
        {
            throw new LogicException('Unused');
        }

        public function hold(HoldStoredValue $command): StoredValueHold
        {
            throw new LogicException('Unused');
        }

        public function capture(CaptureStoredValue $command): StoredValueCapture
        {
            throw new LogicException('Unused');
        }

        public function release(ReleaseStoredValue $command): void {}

        public function refundCredit(RefundToStoredValue $command): StoredValueCredit
        {
            $this->credits[] = $command;

            return new StoredValueCredit(
                accountId: $command->accountId ?? 'wallet-42',
                amount: $command->amount,
                balanceAfter: $command->amount,
                journalId: 'jrn-001',
            );
        }
    };

    $compensator = new DefaultOrderChangeCompensator($paymentsFake, $refundsFake, $storedValueFake);
    $changeId = OrderChangeId::generate();

    // 1. Positive delta -> IncrementAuthorization on existing authorized payment.
    $incAuth = $compensator->compensateIncrease(
        $changeId,
        $order,
        Money::of('180.00', 'RUB'),
        new CompensationPreference(onIncrease: PositiveDeltaStrategy::IncrementAuthorization),
    );
    expect($incAuth->strategy)->toBe(CompensationStrategy::IncrementAuthorization)
        ->and($incAuth->amount)->toEqual(Money::of('180.00', 'RUB'))
        ->and($incAuth->paymentId)->not->toBeNull()
        ->and($incAuth->details['parent_payment_id'])->toBe($authPaymentId->value)
        ->and($initiatedPayments)->toHaveCount(1)
        ->and($initiatedPayments[0]->gateway)->toBe('stripe');

    // 2. Positive delta -> DeltaCharge on explicit gateway.
    $deltaCharge = $compensator->compensateIncrease(
        $changeId,
        $order,
        Money::of('50.00', 'RUB'),
        new CompensationPreference(onIncrease: PositiveDeltaStrategy::DeltaCharge, paymentGateway: 'manual'),
    );
    expect($deltaCharge->strategy)->toBe(CompensationStrategy::DeltaCharge)
        ->and($deltaCharge->amount)->toEqual(Money::of('50.00', 'RUB'))
        ->and($initiatedPayments[1]->gateway)->toBe('manual');

    // 3. Negative delta -> PartialRefund via Refunds.
    $partialRefund = $compensator->compensateDecrease(
        $changeId,
        $order,
        Money::of('60.00', 'RUB'),
        new CompensationPreference(onDecrease: NegativeDeltaStrategy::PartialRefund),
    );
    expect($partialRefund->strategy)->toBe(CompensationStrategy::PartialRefund)
        ->and($partialRefund->refundId)->not->toBeNull()
        ->and($issuedRefunds)->toHaveCount(1)
        ->and($issuedRefunds[0]->amount)->toEqual(Money::of('60.00', 'RUB'));

    // 4. Negative delta -> WalletCredit via StoredValueTender.
    $wallet = $compensator->compensateDecrease(
        $changeId,
        $order,
        Money::of('60.00', 'RUB'),
        new CompensationPreference(onDecrease: NegativeDeltaStrategy::WalletCredit),
    );
    expect($wallet->strategy)->toBe(CompensationStrategy::WalletCredit)
        ->and($wallet->storedValueAccountId)->toBe('wallet-42')
        ->and($wallet->storedValueJournalId)->toBe('jrn-001')
        ->and($walletCredits)->toHaveCount(1)
        ->and($walletCredits[0]->amount)->toEqual(Money::of('60.00', 'RUB'))
        ->and($walletCredits[0]->customer)->toEqual($order->customer);
});

it('enforces preview dry-run immutability and throws 409 ORDERS_ORDER_CHANGE_VERSION_CONFLICT (INV-OMS-001) on stale expectedOrderVersion', function (): void {
    // Verify ProblemDefinition registers 409 Conflict for OrderChangeVersionConflict.
    $definitions = (new SalesProblemDefinitions)->definitions();
    $conflictDef = array_find($definitions, static fn (ProblemDefinition $definition): bool => $definition->code === SalesErrorCode::OrderChangeVersionConflict);

    if (! $conflictDef instanceof ProblemDefinition) {
        throw new RuntimeException('Expected the order-change version conflict problem.');
    }

    expect($conflictDef->defaultStatus)->toBe(409)
        ->and($conflictDef->category)->toBe(ErrorCategory::Conflict);

    // Boot an in-memory SQLite DB with PG-compatible boolean casting to test DatabaseOrderChanges end-to-end.
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $baseConn = $capsule->getConnection();
    $conn = new class($baseConn->getPdo(), ':memory:') extends SQLiteConnection
    {
        /**
         * @param  array<int|string, mixed>  $bindings
         * @param  array<int|string, mixed>  $fetchUsing
         * @return list<mixed>
         */
        public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
        {
            $rows = [];

            foreach (parent::select($query, $bindings, $useReadPdo, $fetchUsing) as $row) {
                if (is_object($row)) {
                    foreach (['requires_shipping', 'tax_included'] as $col) {
                        if (property_exists($row, $col) && is_int($row->{$col})) {
                            $row->{$col} = (bool) $row->{$col};
                        }
                    }
                }

                $rows[] = $row;
            }

            return $rows;
        }
    };
    $schema = $conn->getSchemaBuilder();

    $schema->create('cmz_orders', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('number');
        $table->uuid('store_id');
        $table->uuid('channel_id');
        $table->uuid('cart_id');
        $table->string('origin');
        $table->string('state');
        $table->string('payment_state');
        $table->string('fulfillment_state');
        $table->string('cancel_reason')->nullable();
        $table->string('currency', 3);
        $table->text('locale');
        $table->string('customer_ref')->nullable();
        $table->text('customer_groups');
        $table->text('customer_email')->nullable();
        $table->text('customer_name')->nullable();
        $table->text('codes');
        $table->text('shipping_address')->nullable();
        $table->text('billing_address')->nullable();
        $table->text('delivery')->nullable();
        $table->text('order_adjustments');
        $table->text('delivery_adjustments');
        $table->text('delivery_tax_lines');
        $table->bigInteger('subtotal_minor');
        $table->bigInteger('discount_total_minor');
        $table->bigInteger('delivery_total_minor');
        $table->bigInteger('tax_total_minor');
        $table->bigInteger('total_minor');
        $table->bigInteger('amount_due_minor');
        $table->bigInteger('authorized_minor')->default(0);
        $table->bigInteger('captured_minor')->default(0);
        $table->bigInteger('refunded_minor')->default(0);
        $table->string('quote_fingerprint', 64);
        $table->string('payment_due_at')->nullable();
        $table->string('placed_at');
        $table->string('completed_at')->nullable();
        $table->string('cancelled_at')->nullable();
        $table->string('created_by_ref')->nullable();
        $table->integer('version')->default(1);
        $table->string('created_at');
        $table->string('updated_at');
    });

    $schema->create('cmz_order_lines', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('order_id');
        $table->integer('position');
        $table->string('sellable_ref');
        $table->string('seller_ref')->nullable();
        $table->string('sku');
        $table->text('name');
        $table->string('kind');
        $table->boolean('requires_shipping');
        $table->text('options');
        $table->text('tax_class')->nullable();
        $table->integer('weight_grams')->nullable();
        $table->integer('quantity');
        $table->string('currency', 3);
        $table->bigInteger('unit_price_minor');
        $table->bigInteger('compare_at_minor')->nullable();
        $table->boolean('tax_included');
        $table->text('price_source');
        $table->bigInteger('subtotal_minor');
        $table->bigInteger('total_minor');
        $table->text('adjustments');
        $table->text('tax_lines');
        $table->integer('cancelled_quantity')->default(0);
        $table->integer('fulfilled_quantity')->default(0);
        $table->integer('returned_quantity')->default(0);
    });

    $schema->create('cmz_order_events', static function (Blueprint $table): void {
        $table->id();
        $table->uuid('order_id');
        $table->string('type');
        $table->string('actor_ref')->nullable();
        $table->text('data');
        $table->string('created_at');
    });

    $schema->create('cmz_order_changes', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('order_id');
        $table->string('status');
        $table->integer('base_order_version');
        $table->integer('confirmed_order_version')->nullable();
        $table->string('currency', 3);
        $table->bigInteger('amount_delta_minor')->default(0);
        $table->bigInteger('tax_delta_minor')->default(0);
        $table->text('description')->nullable();
        $table->string('compensation_strategy')->nullable();
        $table->text('compensation_result');
        $table->text('excerpt_before')->nullable();
        $table->text('excerpt_after')->nullable();
        $table->string('created_by_ref')->nullable();
        $table->string('confirmed_by_ref')->nullable();
        $table->string('confirmed_at')->nullable();
        $table->string('cancelled_at')->nullable();
        $table->string('created_at');
        $table->string('updated_at');
    });

    $schema->create('cmz_order_change_actions', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('order_change_id');
        $table->integer('position');
        $table->string('type');
        $table->text('payload');
        $table->string('created_at');
    });

    $order = ocSampleOrder(version: 1);
    $ts = '2026-11-26 12:00:00.000000+00';

    $conn->table('cmz_orders')->insert([
        'id' => $order->id->value,
        'number' => $order->number,
        'store_id' => $order->store->value,
        'channel_id' => $order->channel->value,
        'cart_id' => $order->cart->value,
        'origin' => $order->origin->value,
        'state' => $order->state->value,
        'payment_state' => $order->paymentState->value,
        'fulfillment_state' => $order->fulfillmentState->value,
        'currency' => $order->currency->code,
        'locale' => $order->locale,
        'customer_ref' => (string) $order->customer,
        'customer_groups' => '[]',
        'customer_email' => $order->customerEmail,
        'customer_name' => $order->customerName,
        'codes' => '[]',
        'shipping_address' => $order->shippingAddress === null ? null : SalesCodec::addressToJson($order->shippingAddress),
        'billing_address' => null,
        'delivery' => $order->delivery === null ? null : SalesCodec::deliveryToJson($order->delivery),
        'order_adjustments' => '[]',
        'delivery_adjustments' => '[]',
        'delivery_tax_lines' => SalesCodec::taxLinesToJson($order->deliveryTaxLines),
        'subtotal_minor' => $order->totals->subtotal->amountMinor,
        'discount_total_minor' => 0,
        'delivery_total_minor' => $order->totals->deliveryTotal->amountMinor,
        'tax_total_minor' => $order->totals->taxTotal->amountMinor,
        'total_minor' => $order->totals->total->amountMinor,
        'amount_due_minor' => $order->amountDue->amountMinor,
        'authorized_minor' => 0,
        'captured_minor' => 0,
        'refunded_minor' => 0,
        'quote_fingerprint' => $order->quoteFingerprint,
        'placed_at' => $ts,
        'version' => 1,
        'created_at' => $ts,
        'updated_at' => $ts,
    ]);

    foreach ($order->lines as $idx => $line) {
        $conn->table('cmz_order_lines')->insert([
            'id' => $line->id->value,
            'order_id' => $order->id->value,
            'position' => $idx + 1,
            'sellable_ref' => (string) $line->sellable,
            'sku' => $line->sku,
            'name' => $line->name,
            'kind' => $line->kind->value,
            'requires_shipping' => $line->requiresShipping,
            'options' => SalesCodec::optionsToJson($line->options),
            'tax_class' => $line->taxClass,
            'weight_grams' => $line->weightGrams,
            'quantity' => $line->quantity,
            'currency' => $order->currency->code,
            'unit_price_minor' => $line->unitPrice->amountMinor,
            'compare_at_minor' => null,
            'tax_included' => $line->taxIncluded,
            'price_source' => $line->priceSource,
            'subtotal_minor' => $line->subtotal->amountMinor,
            'total_minor' => $line->total->amountMinor,
            'adjustments' => SalesCodec::adjustmentsToJson($line->adjustments),
            'tax_lines' => SalesCodec::taxLinesToJson($line->taxLines),
        ]);
    }

    $resolver = new readonly class($conn) implements ConnectionResolverInterface
    {
        public function __construct(private ConnectionInterface $conn) {}

        public function connection($name = null): ConnectionInterface
        {
            return $this->conn;
        }

        public function getDefaultConnection(): string
        {
            return 'default';
        }

        public function setDefaultConnection($name): void {}
    };

    $transactions = new readonly class($conn) implements Transactions
    {
        public function __construct(private ConnectionInterface $conn) {}

        public function run(callable $work, ?int $lockTimeoutMs = null): mixed
        {
            return $this->conn->transaction(static fn () => $work());
        }

        public function inTransaction(): bool
        {
            return $this->conn->transactionLevel() > 0;
        }
    };

    $outbox = new class implements Outbox
    {
        public function record(IntegrationEvent $event): void {}

        /** @return list<mixed> */
        public function claim(int $limit, ?string $worker = null): array
        {
            return [];
        }

        public function markPublished(int $id): void {}

        public function markFailed(int $id, string $reason, int $retryAfterSeconds = 60): void {}
    };

    $events = new class implements CommittedEvents
    {
        public function dispatch(object $event): void {}
    };

    $stock = new class implements StockReservations
    {
        public function reserve(ReserveStock $command): ReservationResult
        {
            return new ReservationResult($command->demand, []);
        }

        public function consume(ConsumeReservation $command): void {}

        public function release(ReleaseReservation $command): void {}
    };

    $compensator = new class implements OrderChangeCompensator
    {
        public function compensateIncrease(OrderChangeId $changeId, OrderView $order, Money $delta, CompensationPreference $preference, ?IdempotencyKey $idempotencyKey = null, ?ActorRef $actor = null): CompensationOutcome
        {
            return new CompensationOutcome(CompensationStrategy::Deferred, $delta);
        }

        public function compensateDecrease(OrderChangeId $changeId, OrderView $order, Money $overpaid, CompensationPreference $preference, ?IdempotencyKey $idempotencyKey = null, ?ActorRef $actor = null): CompensationOutcome
        {
            return new CompensationOutcome(CompensationStrategy::Deferred, $overpaid);
        }
    };

    $options = new CommerzOptions;
    $ownership = new class implements SchemaOwnership
    {
        public function declare(string $owner, array $nouns): void {}

        public function ownerOf(string $logical): string
        {
            return 'commerz.sales';
        }

        public function owners(): array
        {
            return ['commerz.sales' => ['order']];
        }
    };
    $tables = new TableNames(static fn (): PackmanTables => new PackmanTables('cmz_', $ownership, $resolver));
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-26T12:10:00+00:00'));

    $service = new DatabaseOrderChanges(
        transactions: $transactions,
        locks: new OrderLocks($resolver, $options, $tables),
        orders: new OrderRows($resolver, $options, $tables),
        writer: new OrderWriter($resolver, $options, $tables, $transactions),
        status: new OrderStatus($resolver, $options, $tables, new OrderJournal($resolver, $options, $tables), $outbox, $events),
        journal: new OrderJournal($resolver, $options, $tables),
        changes: new OrderChangeRows($resolver, $options, $tables),
        calculator: ocCalculator([
            'variant:v3' => new SellableSnapshot(
                ref: SellableRef::of('variant', 'v3'),
                sku: 'SKU-3',
                name: 'Jacket',
                kind: SellableKind::Physical,
                requiresShipping: true,
                basePrice: Money::of('150.00', 'RUB'),
                taxClass: 'standard',
                weightGrams: 500,
            ),
        ]),
        compensator: $compensator,
        stock: $stock,
        outbox: $outbox,
        events: $events,
        clock: $clock,
    );

    // 1. Create draft & add action.
    $change = $service->create(new CreateOrderChange($order->id, 'Add jacket'));
    expect($change->status)->toBe(OrderChangeStatus::Draft)
        ->and($change->baseOrderVersion)->toBe(1);

    $change = $service->addAction(new AddOrderChangeAction($change->id, new AddLineAction(SellableRef::of('variant', 'v3'), 1)));
    expect($change->actions)->toHaveCount(1);

    // 2. Preview dry-run: returns deltas WITHOUT mutating cmz_orders or cmz_order_lines!
    $preview = $service->preview($change->id);
    expect($preview->amountDelta)->toEqual(Money::of('180.00', 'RUB'))
        ->and($preview->taxDelta)->toEqual(Money::of('30.00', 'RUB'))
        ->and($conn->table('cmz_orders')->where('id', $order->id->value)->value('version'))->toBe(1)
        ->and($conn->table('cmz_orders')->where('id', $order->id->value)->value('total_minor'))->toBe(31200)
        ->and($conn->table('cmz_order_lines')->where('order_id', $order->id->value)->count())->toBe(2);

    // 3. Simulate concurrent modification bumping order version to 2 -> confirm(expectedOrderVersion: 1) MUST throw 409 INV-OMS-001!
    $conn->table('cmz_orders')->where('id', $order->id->value)->update(['version' => 2]);

    try {
        $service->confirm($change->id, expectedOrderVersion: 1);
        Assert::fail('Expected OrderChangeVersionConflict to be thrown.');
    } catch (OrderChangeVersionConflict $e) {
        expect($e->code())->toBe('sales.order_change_version_conflict')
            ->and($e->category())->toBe(ErrorCategory::Conflict)
            ->and($e->expectedVersion)->toBe(1)
            ->and($e->actualVersion)->toBe(2)
            ->and($e->context()['domain_code'])->toBe('ORDERS_ORDER_CHANGE_VERSION_CONFLICT')
            ->and($e->context()['invariant'])->toBe('INV-OMS-001')
            ->and($e->getMessage())->toContain('ORDERS_ORDER_CHANGE_VERSION_CONFLICT')
            ->and($e->getMessage())->toContain('INV-OMS-001');
    }

    // Order and change remain untouched after OCC failure.
    expect($service->find($change->id)->status)->toBe(OrderChangeStatus::Draft)
        ->and($conn->table('cmz_order_lines')->where('order_id', $order->id->value)->count())->toBe(2);

    // 4. Cancel draft -> subsequent addAction fails with OrderChangeNotOpen.
    $cancelled = $service->cancel(new CancelOrderChange($change->id));
    expect($cancelled->status)->toBe(OrderChangeStatus::Cancelled);

    expect(fn () => $service->addAction(new AddOrderChangeAction($change->id, new RemoveLineAction($order->lines[1]->id))))
        ->toThrow(OrderChangeNotOpen::class);
});
