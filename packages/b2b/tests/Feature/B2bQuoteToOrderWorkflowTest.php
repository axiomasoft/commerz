<?php

declare(strict_types=1);

namespace Commerz\B2b\Tests\Feature;

use Commerz\B2b\Approvals\Data\ApproveFlow;
use Commerz\B2b\Approvals\Data\CreateApprovalRule;
use Commerz\B2b\Approvals\Data\InitiateApprovalFlow;
use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\Approvals\Exceptions\ApprovalNotGranted;
use Commerz\B2b\Approvals\Exceptions\ApprovalRequired;
use Commerz\B2b\B2bServiceProvider;
use Commerz\B2b\BusinessUnits\Data\AssignAssociate;
use Commerz\B2b\BusinessUnits\Data\CreateBusinessUnit;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateUnauthorized;
use Commerz\B2b\Facades\B2b;
use Commerz\B2b\PaymentTerms\Data\ConfigureCreditAccount;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Commerz\B2b\Quotes\Contracts\OrderPlacementPort;
use Commerz\B2b\Quotes\Data\ConvertQuoteToOrder;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteLineView;
use Commerz\B2b\Quotes\Data\PlacedB2bOrder;
use Commerz\B2b\Quotes\Data\PlaceLockedQuoteOrder;
use Commerz\B2b\Quotes\Data\PublishNegotiatedQuote;
use Commerz\B2b\Quotes\Data\QuoteRequestLineInput;
use Commerz\B2b\Quotes\Data\RequestRenegotiation;
use Commerz\B2b\Quotes\Data\StagedLineOverride;
use Commerz\B2b\Quotes\Data\StageQuote;
use Commerz\B2b\Quotes\Data\SubmitQuoteRequest;
use Commerz\B2b\Quotes\Data\UpdateStagedQuote;
use Commerz\B2b\Quotes\Enums\NegotiatedQuoteStatus;
use Commerz\B2b\Quotes\Enums\QuoteRequestStatus;
use Commerz\B2b\Quotes\Enums\StagedQuoteStatus;
use Commerz\B2b\Quotes\Exceptions\QuoteExpired;
use Commerz\B2b\Tests\Support\B2bSqliteSchema;
use Commerz\CommerzServiceProvider;
use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartLineView;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Testkit\PackageTestCase;
use Commerz\Time\Contracts\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class SalesLockedOrderPlacementAdapter implements OrderPlacementPort
{
    public function placeLockedQuote(PlaceLockedQuoteOrder $request): PlacedB2bOrder
    {
        $draft = Sales::orders()->draft(new DraftOrder(
            context: $request->context,
            lines: array_map(
                static fn (NegotiatedQuoteLineView $line): DraftLine => new DraftLine($line->sellable->ref, $line->quantity),
                $request->lines,
            ),
            actor: $request->actor,
            codes: $request->codes,
            shippingAddress: $request->shippingAddress,
            billingAddress: $request->billingAddress,
            deliveryMethod: $request->deliveryMethod,
        ));

        // Quotes the Sales draft cart through the real QuotePipeline while LockedPriceScope is active:
        $settledQuote = Sales::carts()->quote($draft->id);

        if (! $settledQuote->total->equals($request->lockedTotals->total)) {
            throw new QuoteChanged($request->lockedTotals->total, $settledQuote->total);
        }

        $orderId = OrderId::generate();
        $number = 'B2B-'.strtoupper(substr(str_replace('-', '', $orderId->value), -8));
        $connection = config('commerz.connection');
        if (! is_string($connection)) {
            throw new RuntimeException('commerz.connection must be a string.');
        }

        $db = DB::connection($connection);
        $now = $request->placedAt->format('Y-m-d H:i:s.uP');

        $db->table('cmz_orders')->insert([
            'id' => $orderId->value,
            'number' => $number,
            'store_id' => $orderId->value,
            'channel_id' => $request->context->channel->value,
            'cart_id' => $draft->id->value,
            'origin' => 'draft',
            'state' => 'placed',
            'payment_state' => 'unpaid',
            'fulfillment_state' => 'unfulfilled',
            'currency' => $request->context->currency->code,
            'locale' => $request->context->locale,
            'customer_ref' => $request->context->customer === null ? null : (string) $request->context->customer,
            'customer_groups' => '[]',
            'codes' => '[]',
            'order_adjustments' => '[]',
            'delivery_adjustments' => '[]',
            'delivery_tax_lines' => '[]',
            'subtotal_minor' => $settledQuote->totals->subtotal->amountMinor,
            'discount_total_minor' => $settledQuote->totals->discountTotal->amountMinor,
            'delivery_total_minor' => $settledQuote->totals->deliveryTotal->amountMinor,
            'tax_total_minor' => $settledQuote->totals->taxTotal->amountMinor,
            'total_minor' => $settledQuote->total->amountMinor,
            'amount_due_minor' => $settledQuote->total->amountMinor,
            'quote_fingerprint' => $settledQuote->fingerprint,
            'payment_due_at' => $request->paymentSchedule?->finalDueAt->format('Y-m-d H:i:s.uP'),
            'placed_at' => $now,
            'created_by_ref' => $request->actor === null ? null : (string) $request->actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($settledQuote->lines as $index => $line) {
            $db->table('cmz_order_lines')->insert([
                'id' => $line->lineId,
                'order_id' => $orderId->value,
                'position' => $index + 1,
                'sellable_ref' => (string) $line->sellable->ref,
                'sku' => $line->sellable->sku,
                'name' => $line->sellable->name,
                'kind' => $line->sellable->kind->value,
                'requires_shipping' => $line->sellable->requiresShipping ? 1 : 0,
                'options' => '{}',
                'quantity' => $line->quantity,
                'currency' => $line->unitPrice->currency->code,
                'unit_price_minor' => $line->unitPrice->amountMinor,
                'tax_included' => $line->taxIncluded ? 1 : 0,
                'price_source' => $line->priceSource,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
                'adjustments' => '[]',
                'tax_lines' => '[]',
            ]);
        }

        return new PlacedB2bOrder(
            orderRef: 'order:'.$orderId->value,
            orderNumber: $number,
            total: $settledQuote->total,
            paymentDueAt: $request->paymentSchedule?->finalDueAt,
        );
    }
}

final class B2bQuoteToOrderWorkflowTest extends PackageTestCase
{
    private FrozenClock $frozenClock;

    private ChannelId $channelId;

    protected function commerzProviders(): array
    {
        return [
            CommerzServiceProvider::class,
            SalesServiceProvider::class,
            B2bServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.stock', 'unlimited');
        $app['config']->set('commerz-sales.checkout.require_delivery', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        B2bSqliteSchema::boot('b2b_e2e_sqlite', withSales: true);

        $this->frozenClock = new FrozenClock(new DateTimeImmutable('2026-11-26T10:00:00Z'));
        $app = $this->app;
        if (! $app instanceof Application) {
            self::fail('Application is not booted.');
        }

        $app->instance(Clock::class, $this->frozenClock);
        $app->singleton(OrderPlacementPort::class, SalesLockedOrderPlacementAdapter::class);

        $store = app(Stores::class)->create(new CreateStore('acme-b2b-store', 'Acme Industrial Store', Currency::of('USD'), 'B2B-'));
        $channel = app(Channels::class)->create(new CreateChannel($store->id, 'b2b-portal', 'B2B Portal', [Currency::of('USD')]));
        $this->channelId = $channel->id;
    }

    public function test_full_cart_to_quote_request_to_staged_to_negotiated_to_renegotiation_to_approval_to_net30_order_at_locked_prices(): void
    {
        $commerce = Commerce::fake();
        $pumpRef = SellableRef::of('product', 'industrial-pump');
        $valveRef = SellableRef::of('product', 'titanium-valve');

        $commerce->sellables->add(
            FakeSellables::snapshot($pumpRef, Money::of('100.00', 'USD'), SellableKind::Physical),
            FakeSellables::snapshot($valveRef, Money::of('50.00', 'USD'), SellableKind::Physical),
        );

        // 1. Setup BusinessUnit hierarchy, credit limit, associates, and budget approval threshold
        $hq = B2b::businessUnits()->create(new CreateBusinessUnit(' Stark-HQ ', 'Stark Industries HQ', Currency::of('USD')));
        $mfg = B2b::businessUnits()->create(new CreateBusinessUnit('stark-mfg', 'Stark Manufacturing Division', Currency::of('USD'), parentId: $hq->id));

        B2b::creditAccounts()->configure(new ConfigureCreditAccount($hq->id, Money::of('50000.00', 'USD')));

        $buyer = CustomerRef::of('user', 'buyer-peter');
        $approver = CustomerRef::of('user', 'vp-pepper');
        $salesRep = ActorRef::of('staff', 'rep-happy');

        B2b::businessUnits()->assignAssociate(new AssignAssociate($mfg->id, $buyer, AssociateRole::Buyer, perOrderSpendingLimit: Money::of('3000.00', 'USD')));
        B2b::businessUnits()->assignAssociate(new AssignAssociate($hq->id, $approver, AssociateRole::Approver));

        B2b::approvals()->createRule(new CreateApprovalRule(
            businessUnitId: $hq->id,
            name: 'Orders Over $5,000',
            thresholdAmount: Money::of('5000.00', 'USD'),
            approverRole: AssociateRole::Approver,
            requiredApprovals: 1,
            appliesToDescendants: true,
        ));

        // 2. Buyer builds a real Sales Cart ($100 * 100 + $50 * 20 = $11,000 catalog total)
        $shippingAddress = new Address('US', 'US-NY', 'New York', '10001', ['200 Park Ave'], 'Stark Receiving', '+12125550100');
        $context = CommerceContext::for($this->channelId, Currency::of('USD'), 'en', $this->frozenClock->now())->withCustomer($buyer);

        $cart = Sales::carts()->create(new CreateCart($context, actor: ActorRef::of('user', 'buyer-peter')));
        Sales::carts()->addLine(new AddCartLine($cart->id, $pumpRef, 100));
        $cart = Sales::carts()->addLine(new AddCartLine($cart->id, $valveRef, 20));
        $cart = Sales::carts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, $shippingAddress));

        $cartQuote = Sales::carts()->quote($cart->id);
        self::assertEquals(Money::of('11000.00', 'USD'), $cartQuote->total);

        // 3. Cart -> QuoteRequest (Stage 1)
        $quoteRequest = B2b::quotes()->submitRequest(new SubmitQuoteRequest(
            businessUnitId: $mfg->id,
            buyer: $buyer,
            context: $context,
            lines: array_map(
                static fn (CartLineView $line): QuoteRequestLineInput => new QuoteRequestLineInput(
                    lineId: $line->id,
                    sellable: $line->sellable,
                    quantity: $line->quantity,
                ),
                $cart->lines,
            ),
            sourceCartId: $cart->id->value,
            requestedPaymentTerms: PaymentTerms::net30(),
            purchaseOrderNumber: 'PO-STARK-2026-99',
            buyerNote: 'Requesting volume discount for Q4 assembly run.',
            shippingAddress: $cart->shippingAddress,
        ));

        self::assertSame(QuoteRequestStatus::Submitted, $quoteRequest->status);
        self::assertSame($cart->id->value, $quoteRequest->sourceCartId);
        self::assertCount(2, $quoteRequest->lines);

        // 4. QuoteRequest -> StagedQuote -> NegotiatedQuote rev 1 ($85 * 100 + $45 * 20 = $9,400)
        $pumpLineId = $quoteRequest->lines[0]->lineId;
        $valveLineId = $quoteRequest->lines[1]->lineId;
        $validUntil = $this->frozenClock->now()->modify('+14 days');

        $stagedRev1 = B2b::quotes()->stage(new StageQuote(
            quoteRequestId: $quoteRequest->id,
            sellerActor: $salesRep,
            validUntil: $validUntil,
            paymentTerms: PaymentTerms::net30(),
            lineOverrides: [
                new StagedLineOverride($pumpLineId, negotiatedUnitPrice: Money::of('85.00', 'USD')),
                new StagedLineOverride($valveLineId, negotiatedUnitPrice: Money::of('45.00', 'USD')),
            ],
            sellerNote: 'Initial volume offer.',
        ));

        self::assertSame(StagedQuoteStatus::Draft, $stagedRev1->status);
        self::assertEquals(Money::of('9400.00', 'USD'), $stagedRev1->total);

        $negotiatedRev1 = B2b::quotes()->publish(new PublishNegotiatedQuote($stagedRev1->id, $salesRep));
        self::assertSame(NegotiatedQuoteStatus::Sent, $negotiatedRev1->status);
        self::assertSame(1, $negotiatedRev1->revision);
        self::assertEquals(Money::of('9400.00', 'USD'), $negotiatedRev1->lockedTotal);

        // 5. Buyer calls requestRenegotiation -> Seller updates StagedQuote rev 2 ($80 * 100 + $40 * 20 = $8,800) -> NegotiatedQuote rev 2
        $renegotiation = B2b::quotes()->requestRenegotiation(new RequestRenegotiation(
            negotiatedQuoteId: $negotiatedRev1->id,
            buyer: $buyer,
            comment: 'Can we reach $80 on pumps and $40 on valves?',
        ));

        self::assertSame(NegotiatedQuoteStatus::RenegotiationRequested, $renegotiation->negotiatedQuote->status);
        self::assertSame(QuoteRequestStatus::RenegotiationRequested, $renegotiation->quoteRequest->status);
        self::assertSame(2, $renegotiation->stagedQuote->revision);
        self::assertSame(StagedQuoteStatus::Draft, $renegotiation->stagedQuote->status);

        $stagedRev2 = B2b::quotes()->updateStaged(new UpdateStagedQuote(
            stagedQuoteId: $renegotiation->stagedQuote->id,
            sellerActor: $salesRep,
            lineOverrides: [
                new StagedLineOverride($pumpLineId, negotiatedUnitPrice: Money::of('80.00', 'USD')),
                new StagedLineOverride($valveLineId, negotiatedUnitPrice: Money::of('40.00', 'USD')),
            ],
            sellerNote: 'Approved final Q4 executive pricing.',
        ));

        $negotiatedRev2 = B2b::quotes()->publish(new PublishNegotiatedQuote($stagedRev2->id, $salesRep));
        self::assertSame(2, $negotiatedRev2->revision);
        self::assertSame(NegotiatedQuoteStatus::Sent, $negotiatedRev2->status);
        self::assertEquals(Money::of('8800.00', 'USD'), $negotiatedRev2->lockedTotal);

        // 6. Advance clock by 2 days (still before validUntil) AND raise live catalog prices!
        // Pump jumps $100 -> $140, Valve jumps $50 -> $75 (live price would be $15,500, NOT $8,800!)
        $this->frozenClock->advance('2 days');
        $commerce->sellables->add(
            FakeSellables::snapshot($pumpRef, Money::of('140.00', 'USD'), SellableKind::Physical),
            FakeSellables::snapshot($valveRef, Money::of('75.00', 'USD'), SellableKind::Physical),
        );
        self::assertEquals(Money::of('15500.00', 'USD'), Sales::carts()->quote($cart->id)->total);

        // 7. Conversion without approval fails because $8,800 exceeds $3,000 associate cap & $5,000 HQ budget threshold
        try {
            B2b::quotes()->convertToOrder(new ConvertQuoteToOrder($negotiatedRev2->id, $buyer));
            self::fail('Expected ApprovalRequired to be thrown.');
        } catch (ApprovalRequired $e) {
            self::assertContains('exceeds_associate_spending_limit', $e->reasons);
            self::assertContains('exceeds_budget_threshold:Orders Over $5,000', $e->reasons);
        }

        // 8. Initiate ApprovalFlow -> Pending -> block conversion while pending -> reject self-approval -> approve by HQ VP
        $flow = B2b::approvals()->initiate(new InitiateApprovalFlow(
            businessUnitId: $mfg->id,
            requestedBy: $buyer,
            subjectRef: 'negotiated_quote:'.$negotiatedRev2->id->value,
            amount: $negotiatedRev2->lockedTotal,
        ));
        self::assertSame(ApprovalStatus::Pending, $flow->status);

        try {
            B2b::quotes()->convertToOrder(new ConvertQuoteToOrder($negotiatedRev2->id, $buyer, approvalFlowId: $flow->id));
            self::fail('Expected ApprovalNotGranted while approval flow is pending.');
        } catch (ApprovalNotGranted $e) {
            self::assertSame(ApprovalStatus::Pending, $e->status);
        }

        try {
            B2b::approvals()->approve(new ApproveFlow($flow->id, $buyer));
            self::fail('Expected self-approval to be forbidden.');
        } catch (AssociateUnauthorized) {
        }

        $approvedFlow = B2b::approvals()->approve(new ApproveFlow($flow->id, $approver, 'Approved for Q4 production'));
        self::assertSame(ApprovalStatus::Approved, $approvedFlow->status);

        // 9. Convert NegotiatedQuote -> Order(Net30) at locked prices ($8,800.00, NOT $15,500.00!)
        $idempotencyKey = IdempotencyKey::of('convert-quote-rev2-key');
        $converted = B2b::quotes()->convertToOrder(new ConvertQuoteToOrder(
            negotiatedQuoteId: $negotiatedRev2->id,
            buyer: $buyer,
            approvalFlowId: $approvedFlow->id,
            idempotencyKey: $idempotencyKey,
        ));

        // Idempotent replay returns identical conversion result
        $replayed = B2b::quotes()->convertToOrder(new ConvertQuoteToOrder(
            negotiatedQuoteId: $negotiatedRev2->id,
            buyer: $buyer,
            approvalFlowId: $approvedFlow->id,
            idempotencyKey: $idempotencyKey,
        ));
        self::assertSame($converted->orderRef, $replayed->orderRef);

        $orderId = substr($converted->orderRef, strlen('order:'));
        $db = DB::connection('b2b_e2e_sqlite');
        $orderRow = $db->table('cmz_orders')->where('id', $orderId)->first();
        $orderLineRows = $db->table('cmz_order_lines')->where('order_id', $orderId)->orderBy('position')->get()->all();

        self::assertNotNull($orderRow);
        self::assertSame(880_000, $orderRow->total_minor);
        self::assertSame($this->frozenClock->now()->modify('+30 days')->format('Y-m-d H:i:s.uP'), $orderRow->payment_due_at);
        self::assertCount(2, $orderLineRows);
        self::assertSame(8_000, $orderLineRows[0]->unit_price_minor);
        self::assertSame(4_000, $orderLineRows[1]->unit_price_minor);
        $priceSource = $orderLineRows[0]->price_source;
        self::assertIsString($priceSource);
        self::assertStringStartsWith('b2b.negotiated_quote:', $priceSource);

        self::assertSame('net_30', $converted->paymentTerms?->code);
        self::assertEquals(Money::zero('USD'), $converted->paymentSchedule?->dueNowAmount);
        self::assertEquals(Money::of('8800.00', 'USD'), $converted->paymentSchedule?->deferredAmount);
        self::assertEquals($this->frozenClock->now()->modify('+30 days'), $converted->paymentSchedule?->finalDueAt);

        $creditAfter = B2b::creditAccounts()->findForBusinessUnit($mfg->id, Currency::of('USD'));
        self::assertEquals(Money::of('8800.00', 'USD'), $creditAfter?->reservedCredit);
        self::assertEquals(Money::of('41200.00', 'USD'), $creditAfter?->availableCredit);
    }

    public function test_enforces_valid_until_expiration_and_forbids_recurring_selling_plan_with_payment_terms_in_quotes(): void
    {
        $commerce = Commerce::fake();
        $itemRef = SellableRef::of('product', 'sensor-pack');
        $commerce->sellables->add(FakeSellables::snapshot($itemRef, Money::of('200.00', 'USD'), SellableKind::Physical));

        $unit = B2b::businessUnits()->create(new CreateBusinessUnit('wayne-ent', 'Wayne Enterprises', Currency::of('USD')));
        $buyer = CustomerRef::of('user', 'lucius');
        B2b::businessUnits()->assignAssociate(new AssignAssociate($unit->id, $buyer, AssociateRole::Admin));

        $context = CommerceContext::for($this->channelId, Currency::of('USD'), 'en', $this->frozenClock->now())->withCustomer($buyer);

        // Forbid combining PaymentTerms with recurring SellingPlan on QuoteRequest
        try {
            B2b::quotes()->submitRequest(new SubmitQuoteRequest(
                businessUnitId: $unit->id,
                buyer: $buyer,
                context: $context,
                lines: [new QuoteRequestLineInput('line-1', $itemRef, 5, sellingPlan: SellingPlanSummary::recurring('monthly-sub'))],
                requestedPaymentTerms: PaymentTerms::net30(),
            ));
            self::fail('Expected RecurringSellingPlanIncompatibleWithPaymentTerms.');
        } catch (RecurringSellingPlanIncompatibleWithPaymentTerms) {
        }

        // Valid quote that expires after validUntil
        $req = B2b::quotes()->submitRequest(new SubmitQuoteRequest(
            businessUnitId: $unit->id,
            buyer: $buyer,
            context: $context,
            lines: [new QuoteRequestLineInput('line-1', $itemRef, 5)],
        ));

        $staged = B2b::quotes()->stage(new StageQuote(
            quoteRequestId: $req->id,
            sellerActor: ActorRef::of('staff', 'rep-1'),
            validUntil: $this->frozenClock->now()->modify('+3 days'),
        ));
        $negotiated = B2b::quotes()->publish(new PublishNegotiatedQuote($staged->id, ActorRef::of('staff', 'rep-1')));

        $this->frozenClock->advance('4 days');

        $this->expectException(QuoteExpired::class);
        B2b::quotes()->convertToOrder(new ConvertQuoteToOrder($negotiated->id, $buyer));
    }
}
