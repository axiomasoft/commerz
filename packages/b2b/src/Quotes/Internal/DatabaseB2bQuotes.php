<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Internal;

use Commerz\B2b\Approvals\Contracts\ApprovalFlows;
use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\Approvals\Data\EvaluateApproval;
use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\Approvals\Exceptions\ApprovalNotGranted;
use Commerz\B2b\Approvals\Exceptions\ApprovalRequired;
use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateUnauthorized;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitInactive;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Internal\B2bCodec;
use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Contracts\CreditLimitValidator;
use Commerz\B2b\PaymentTerms\Data\CreditHoldId;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Data\ReserveCredit;
use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\B2b\PaymentTerms\Data\ValidateCreditTerms;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Commerz\B2b\PaymentTerms\Internal\PaymentTermsCodec;
use Commerz\B2b\Quotes\Contracts\B2bQuotes;
use Commerz\B2b\Quotes\Contracts\OrderPlacementPort;
use Commerz\B2b\Quotes\Data\ConvertedQuoteOrderView;
use Commerz\B2b\Quotes\Data\ConvertQuoteToOrder;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteId;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteLineView;
use Commerz\B2b\Quotes\Data\NegotiatedQuoteView;
use Commerz\B2b\Quotes\Data\PlaceLockedQuoteOrder;
use Commerz\B2b\Quotes\Data\PublishNegotiatedQuote;
use Commerz\B2b\Quotes\Data\QuoteRequestId;
use Commerz\B2b\Quotes\Data\QuoteRequestLineView;
use Commerz\B2b\Quotes\Data\QuoteRequestView;
use Commerz\B2b\Quotes\Data\RenegotiationResult;
use Commerz\B2b\Quotes\Data\RequestRenegotiation;
use Commerz\B2b\Quotes\Data\StagedLineOverride;
use Commerz\B2b\Quotes\Data\StagedQuoteId;
use Commerz\B2b\Quotes\Data\StagedQuoteLineView;
use Commerz\B2b\Quotes\Data\StagedQuoteView;
use Commerz\B2b\Quotes\Data\StageQuote;
use Commerz\B2b\Quotes\Data\SubmitQuoteRequest;
use Commerz\B2b\Quotes\Data\UpdateStagedQuote;
use Commerz\B2b\Quotes\Enums\NegotiatedQuoteStatus;
use Commerz\B2b\Quotes\Enums\QuoteRequestStatus;
use Commerz\B2b\Quotes\Enums\StagedQuoteStatus;
use Commerz\B2b\Quotes\Events\NegotiatedQuoteConverted;
use Commerz\B2b\Quotes\Events\NegotiatedQuotePublished;
use Commerz\B2b\Quotes\Events\QuoteRenegotiationRequested;
use Commerz\B2b\Quotes\Events\QuoteRequestSubmitted;
use Commerz\B2b\Quotes\Exceptions\InvalidQuoteState;
use Commerz\B2b\Quotes\Exceptions\InvalidQuoteValidity;
use Commerz\B2b\Quotes\Exceptions\QuoteAlreadyConverted;
use Commerz\B2b\Quotes\Exceptions\QuoteExpired;
use Commerz\B2b\Quotes\Exceptions\QuoteNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseB2bQuotes implements B2bQuotes
{
    private const string CONVERT_SCOPE = 'b2b.convert_quote';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private B2bOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private CommittedEvents $events,
        private Outbox $outbox,
        private BusinessUnits $businessUnits,
        private ApprovalFlows $approvalFlows,
        private CreditAccounts $creditAccounts,
        private CreditLimitValidator $creditValidator,
        private Sellables $sellables,
        private QuotePipeline $quotePipeline,
        private LockedPriceScope $lockedPriceScope,
        private Container $container,
        private Clock $clock,
    ) {}

    #[Override]
    public function submitRequest(SubmitQuoteRequest $command): QuoteRequestView
    {
        return $this->transactions->run(function () use ($command): QuoteRequestView {
            $unit = $this->businessUnits->get($command->businessUnitId);

            if ($unit->status !== BusinessUnitStatus::Active) {
                throw new BusinessUnitInactive($unit->id, $unit->status);
            }

            $associate = $this->businessUnits->resolveAssociate($unit->id, $command->buyer)
                ?? throw new AssociateNotFound($unit->id, $command->buyer);

            if (! $associate->role->canPurchase()) {
                throw new AssociateUnauthorized($unit->id, $command->buyer, "role '{$associate->role->value}' cannot submit quote requests");
            }

            if ($command->requestedPaymentTerms !== null) {
                foreach ($command->lines as $line) {
                    if ($line->sellingPlan?->isRecurring() === true) {
                        throw new RecurringSellingPlanIncompatibleWithPaymentTerms(
                            paymentTermsCode: $command->requestedPaymentTerms->code,
                            sellingPlanCode: $line->sellingPlan->code,
                        );
                    }
                }
            }

            $id = QuoteRequestId::generate();
            $instant = $this->clock->now();
            $now = PgTimestamp::format($instant);
            $short = strtoupper(substr(str_replace('-', '', $id->value), -8));
            $number = $this->options->quoteRequestPrefix.'-'.$instant->format('Ymd').'-'.$short;

            $this->db()->table($this->tables->table('b2b_quote_requests'))->insert([
                'id' => $id->value,
                'number' => $number,
                'business_unit_id' => $unit->id->value,
                'buyer_ref' => (string) $command->buyer,
                'channel_id' => $command->context->channel->value,
                'currency' => $command->context->currency->code,
                'locale' => $command->context->locale,
                'customer_groups' => B2bCodec::encodeStrings($command->context->customerGroups),
                'source_cart_id' => $command->sourceCartId,
                'lines' => QuoteLineCodec::encodeRequestLines($command->lines),
                'requested_payment_terms' => PaymentTermsCodec::encode($command->requestedPaymentTerms),
                'purchase_order_number' => $command->purchaseOrderNumber,
                'buyer_note' => $command->buyerNote,
                'shipping_address' => B2bCodec::encodeAddress($command->shippingAddress ?? $unit->shippingAddress),
                'billing_address' => B2bCodec::encodeAddress($command->billingAddress ?? $unit->billingAddress),
                'delivery_method' => $command->deliveryMethod,
                'codes' => B2bCodec::encodeStrings($command->codes),
                'status' => QuoteRequestStatus::Submitted->value,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->outbox->record(IntegrationEvent::of(
                type: 'b2b.quote_request_submitted',
                aggregateRef: 'quote_request:'.$id->value,
                payload: [
                    'quote_request_id' => $id->value,
                    'number' => $number,
                    'business_unit_id' => $unit->id->value,
                    'buyer_ref' => (string) $command->buyer,
                    'source_cart_id' => $command->sourceCartId,
                ],
                occurredAt: $instant,
            ));

            $this->events->dispatch(new QuoteRequestSubmitted($id, $number, $unit->id));

            return $this->getRequest($id);
        });
    }

    #[Override]
    public function stage(StageQuote $command): StagedQuoteView
    {
        return $this->transactions->run(function () use ($command): StagedQuoteView {
            $requestRow = $this->db()
                ->table($this->tables->table('b2b_quote_requests'))
                ->where('id', $command->quoteRequestId->value)
                ->lockForUpdate()
                ->first();

            if ($requestRow === null) {
                throw new QuoteNotFound('quote_request', $command->quoteRequestId->value);
            }

            $request = self::hydrateRequest($requestRow);

            if (! in_array($request->status, [QuoteRequestStatus::Submitted, QuoteRequestStatus::InReview, QuoteRequestStatus::RenegotiationRequested], true)) {
                throw new InvalidQuoteState($request->id->value, $request->status->value, 'quote request is not open for staging');
            }

            $instant = $this->clock->now();
            $validUntil = ($command->validUntil ?? $instant->modify("+{$this->options->defaultQuoteValidityDays} days"))
                ->setTimezone(new DateTimeZone('UTC'));

            if ($validUntil <= $instant) {
                throw new InvalidQuoteValidity($validUntil);
            }

            $terms = $command->paymentTerms ?? $request->requestedPaymentTerms;
            [$pricedLines, $totals] = $this->priceStagedLines($request, $command->lineOverrides, $terms);

            $now = PgTimestamp::format($instant);
            $stagedTable = $this->tables->table('b2b_staged_quotes');

            $this->db()
                ->table($stagedTable)
                ->where('quote_request_id', $request->id->value)
                ->where('status', StagedQuoteStatus::Draft->value)
                ->update([
                    'status' => StagedQuoteStatus::Superseded->value,
                    'updated_at' => $now,
                ]);

            $stagedId = StagedQuoteId::generate();
            $this->db()->table($stagedTable)->insert([
                'id' => $stagedId->value,
                'quote_request_id' => $request->id->value,
                'business_unit_id' => $request->businessUnitId->value,
                'revision' => $request->revision,
                'status' => StagedQuoteStatus::Draft->value,
                'seller_actor_ref' => (string) $command->sellerActor,
                'currency' => $request->context->currency->code,
                'line_overrides' => QuoteLineCodec::encodeOverrides($command->lineOverrides),
                'priced_lines' => QuoteLineCodec::encodeStagedLines($pricedLines),
                'subtotal_minor' => $totals->subtotal->amountMinor,
                'discount_total_minor' => $totals->discountTotal->amountMinor,
                'delivery_total_minor' => $totals->deliveryTotal->amountMinor,
                'tax_total_minor' => $totals->taxTotal->amountMinor,
                'total_minor' => $totals->total->amountMinor,
                'payment_terms' => PaymentTermsCodec::encode($terms),
                'valid_until' => PgTimestamp::format($validUntil),
                'seller_note' => $command->sellerNote,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db()
                ->table($this->tables->table('b2b_quote_requests'))
                ->where('id', $request->id->value)
                ->update([
                    'status' => QuoteRequestStatus::InReview->value,
                    'updated_at' => $now,
                ]);

            return $this->getStaged($stagedId);
        });
    }

    #[Override]
    public function updateStaged(UpdateStagedQuote $command): StagedQuoteView
    {
        return $this->transactions->run(function () use ($command): StagedQuoteView {
            $stagedTable = $this->tables->table('b2b_staged_quotes');
            $stagedRow = $this->db()
                ->table($stagedTable)
                ->where('id', $command->stagedQuoteId->value)
                ->lockForUpdate()
                ->first();

            if ($stagedRow === null) {
                throw new QuoteNotFound('staged_quote', $command->stagedQuoteId->value);
            }

            $staged = self::hydrateStaged($stagedRow);

            if ($staged->status !== StagedQuoteStatus::Draft) {
                throw new InvalidQuoteState($staged->id->value, $staged->status->value, 'only draft staged quotes may be updated');
            }

            $request = $this->getRequest($staged->quoteRequestId);
            $instant = $this->clock->now();
            $validUntil = ($command->validUntil ?? $staged->validUntil)->setTimezone(new DateTimeZone('UTC'));

            if ($validUntil <= $instant) {
                throw new InvalidQuoteValidity($validUntil);
            }

            $overrides = $command->lineOverrides ?? $staged->lineOverrides;
            $terms = $command->paymentTerms ?? $staged->paymentTerms;
            [$pricedLines, $totals] = $this->priceStagedLines($request, $overrides, $terms);

            $this->db()
                ->table($stagedTable)
                ->where('id', $staged->id->value)
                ->update([
                    'seller_actor_ref' => (string) $command->sellerActor,
                    'line_overrides' => QuoteLineCodec::encodeOverrides($overrides),
                    'priced_lines' => QuoteLineCodec::encodeStagedLines($pricedLines),
                    'subtotal_minor' => $totals->subtotal->amountMinor,
                    'discount_total_minor' => $totals->discountTotal->amountMinor,
                    'delivery_total_minor' => $totals->deliveryTotal->amountMinor,
                    'tax_total_minor' => $totals->taxTotal->amountMinor,
                    'total_minor' => $totals->total->amountMinor,
                    'payment_terms' => PaymentTermsCodec::encode($terms),
                    'valid_until' => PgTimestamp::format($validUntil),
                    'seller_note' => $command->sellerNote ?? $staged->sellerNote,
                    'updated_at' => PgTimestamp::format($instant),
                ]);

            return $this->getStaged($staged->id);
        });
    }

    #[Override]
    public function publish(PublishNegotiatedQuote $command): NegotiatedQuoteView
    {
        return $this->transactions->run(function () use ($command): NegotiatedQuoteView {
            $stagedTable = $this->tables->table('b2b_staged_quotes');
            $stagedRow = $this->db()
                ->table($stagedTable)
                ->where('id', $command->stagedQuoteId->value)
                ->lockForUpdate()
                ->first();

            if ($stagedRow === null) {
                throw new QuoteNotFound('staged_quote', $command->stagedQuoteId->value);
            }

            $staged = self::hydrateStaged($stagedRow);

            if ($staged->status !== StagedQuoteStatus::Draft) {
                throw new InvalidQuoteState($staged->id->value, $staged->status->value, 'staged quote is already published or closed');
            }

            $instant = $this->clock->now();
            if ($staged->validUntil <= $instant) {
                throw new InvalidQuoteValidity($staged->validUntil);
            }

            $request = $this->getRequest($staged->quoteRequestId);
            $now = PgTimestamp::format($instant);
            $negotiatedTable = $this->tables->table('b2b_negotiated_quotes');

            $this->db()
                ->table($negotiatedTable)
                ->where('quote_request_id', $request->id->value)
                ->whereIn('status', [NegotiatedQuoteStatus::Sent->value, NegotiatedQuoteStatus::RenegotiationRequested->value])
                ->update([
                    'status' => NegotiatedQuoteStatus::Superseded->value,
                    'updated_at' => $now,
                ]);

            $lockedLines = array_map(
                static fn (StagedQuoteLineView $line): NegotiatedQuoteLineView => new NegotiatedQuoteLineView(
                    lineId: $line->lineId,
                    sellable: $line->sellable,
                    quantity: $line->quantity,
                    catalogUnitPrice: $line->catalogUnitPrice,
                    lockedUnitPrice: $line->stagedUnitPrice,
                    subtotal: $line->subtotal,
                    taxTotal: $line->taxTotal,
                    total: $line->total,
                    taxIncluded: $line->taxIncluded,
                    sellingPlan: $line->sellingPlan,
                ),
                $staged->lines,
            );

            $negotiatedId = NegotiatedQuoteId::generate();
            $this->db()->table($negotiatedTable)->insert([
                'id' => $negotiatedId->value,
                'quote_request_id' => $request->id->value,
                'staged_quote_id' => $staged->id->value,
                'business_unit_id' => $request->businessUnitId->value,
                'buyer_ref' => (string) $request->buyer,
                'revision' => $staged->revision,
                'status' => NegotiatedQuoteStatus::Sent->value,
                'channel_id' => $request->context->channel->value,
                'currency' => $request->context->currency->code,
                'locale' => $request->context->locale,
                'customer_groups' => B2bCodec::encodeStrings($request->context->customerGroups),
                'locked_lines' => QuoteLineCodec::encodeNegotiatedLines($lockedLines),
                'subtotal_minor' => $staged->totals->subtotal->amountMinor,
                'discount_total_minor' => $staged->totals->discountTotal->amountMinor,
                'delivery_total_minor' => $staged->totals->deliveryTotal->amountMinor,
                'tax_total_minor' => $staged->totals->taxTotal->amountMinor,
                'total_minor' => $staged->totals->total->amountMinor,
                'payment_terms' => PaymentTermsCodec::encode($staged->paymentTerms),
                'shipping_address' => B2bCodec::encodeAddress($request->shippingAddress),
                'billing_address' => B2bCodec::encodeAddress($request->billingAddress),
                'delivery_method' => $request->deliveryMethod,
                'codes' => B2bCodec::encodeStrings($request->codes),
                'valid_until' => PgTimestamp::format($staged->validUntil),
                'seller_note' => $staged->sellerNote,
                'renegotiation_comment' => null,
                'approval_flow_id' => null,
                'converted_order_ref' => null,
                'sent_at' => $now,
                'converted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db()
                ->table($stagedTable)
                ->where('id', $staged->id->value)
                ->update([
                    'status' => StagedQuoteStatus::Published->value,
                    'seller_actor_ref' => (string) $command->sellerActor,
                    'updated_at' => $now,
                ]);

            $this->db()
                ->table($this->tables->table('b2b_quote_requests'))
                ->where('id', $request->id->value)
                ->update([
                    'status' => QuoteRequestStatus::Quoted->value,
                    'updated_at' => $now,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                type: 'b2b.negotiated_quote_published',
                aggregateRef: 'negotiated_quote:'.$negotiatedId->value,
                payload: [
                    'negotiated_quote_id' => $negotiatedId->value,
                    'quote_request_id' => $request->id->value,
                    'business_unit_id' => $request->businessUnitId->value,
                    'revision' => $staged->revision,
                    'total_minor' => $staged->totals->total->amountMinor,
                    'currency' => $request->context->currency->code,
                    'valid_until' => PgTimestamp::format($staged->validUntil),
                ],
                occurredAt: $instant,
            ));

            $this->events->dispatch(new NegotiatedQuotePublished(
                negotiatedQuoteId: $negotiatedId,
                quoteRequestId: $request->id,
                businessUnitId: $request->businessUnitId,
                revision: $staged->revision,
                validUntil: $staged->validUntil,
            ));

            return $this->getNegotiated($negotiatedId);
        });
    }

    #[Override]
    public function requestRenegotiation(RequestRenegotiation $command): RenegotiationResult
    {
        return $this->transactions->run(function () use ($command): RenegotiationResult {
            $negotiatedTable = $this->tables->table('b2b_negotiated_quotes');
            $row = $this->db()
                ->table($negotiatedTable)
                ->where('id', $command->negotiatedQuoteId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new QuoteNotFound('negotiated_quote', $command->negotiatedQuoteId->value);
            }

            $negotiated = self::hydrateNegotiated($row);
            $instant = $this->clock->now();

            if ($negotiated->status !== NegotiatedQuoteStatus::Sent) {
                throw new InvalidQuoteState($negotiated->id->value, $negotiated->status->value, 'only sent quotes can enter renegotiation');
            }

            if ($negotiated->isExpiredAt($instant)) {
                $this->markExpired($negotiated->id, $instant);
                throw new QuoteExpired($negotiated->id, $negotiated->validUntil);
            }

            $associate = $this->businessUnits->resolveAssociate($negotiated->businessUnitId, $command->buyer)
                ?? throw new AssociateNotFound($negotiated->businessUnitId, $command->buyer);

            if (! $associate->role->canPurchase()) {
                throw new AssociateUnauthorized($negotiated->businessUnitId, $command->buyer, "role '{$associate->role->value}' cannot request renegotiation");
            }

            $previousStaged = $this->getStaged($negotiated->stagedQuoteId);
            $nextRevision = $negotiated->revision + 1;
            $now = PgTimestamp::format($instant);

            $this->db()
                ->table($negotiatedTable)
                ->where('id', $negotiated->id->value)
                ->update([
                    'status' => NegotiatedQuoteStatus::RenegotiationRequested->value,
                    'renegotiation_comment' => $command->comment,
                    'updated_at' => $now,
                ]);

            $this->db()
                ->table($this->tables->table('b2b_quote_requests'))
                ->where('id', $negotiated->quoteRequestId->value)
                ->update([
                    'status' => QuoteRequestStatus::RenegotiationRequested->value,
                    'revision' => $nextRevision,
                    'updated_at' => $now,
                ]);

            $newStagedId = StagedQuoteId::generate();
            $sellerActor = $command->sellerActor ?? $previousStaged->sellerActor;

            $this->db()->table($this->tables->table('b2b_staged_quotes'))->insert([
                'id' => $newStagedId->value,
                'quote_request_id' => $negotiated->quoteRequestId->value,
                'business_unit_id' => $negotiated->businessUnitId->value,
                'revision' => $nextRevision,
                'status' => StagedQuoteStatus::Draft->value,
                'seller_actor_ref' => (string) $sellerActor,
                'currency' => $negotiated->context->currency->code,
                'line_overrides' => QuoteLineCodec::encodeOverrides($previousStaged->lineOverrides),
                'priced_lines' => QuoteLineCodec::encodeStagedLines($previousStaged->lines),
                'subtotal_minor' => $previousStaged->totals->subtotal->amountMinor,
                'discount_total_minor' => $previousStaged->totals->discountTotal->amountMinor,
                'delivery_total_minor' => $previousStaged->totals->deliveryTotal->amountMinor,
                'tax_total_minor' => $previousStaged->totals->taxTotal->amountMinor,
                'total_minor' => $previousStaged->totals->total->amountMinor,
                'payment_terms' => PaymentTermsCodec::encode($previousStaged->paymentTerms),
                'valid_until' => PgTimestamp::format($negotiated->validUntil),
                'seller_note' => $previousStaged->sellerNote,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->outbox->record(IntegrationEvent::of(
                type: 'b2b.quote_renegotiation_requested',
                aggregateRef: 'negotiated_quote:'.$negotiated->id->value,
                payload: [
                    'negotiated_quote_id' => $negotiated->id->value,
                    'reopened_staged_quote_id' => $newStagedId->value,
                    'quote_request_id' => $negotiated->quoteRequestId->value,
                    'next_revision' => $nextRevision,
                    'comment' => $command->comment,
                ],
                occurredAt: $instant,
            ));

            $this->events->dispatch(new QuoteRenegotiationRequested(
                negotiatedQuoteId: $negotiated->id,
                reopenedStagedQuoteId: $newStagedId,
                quoteRequestId: $negotiated->quoteRequestId,
                nextRevision: $nextRevision,
            ));

            return new RenegotiationResult(
                negotiatedQuote: $this->getNegotiated($negotiated->id),
                stagedQuote: $this->getStaged($newStagedId),
                quoteRequest: $this->getRequest($negotiated->quoteRequestId),
            );
        });
    }

    #[Override]
    public function convertToOrder(ConvertQuoteToOrder $command): ConvertedQuoteOrderView
    {
        return $this->transactions->run(function () use ($command): ConvertedQuoteOrderView {
            $work = fn (): ConvertedQuoteOrderView => $this->executeConvert($command);

            if ($command->idempotencyKey === null) {
                return $work();
            }

            $fingerprint = Fingerprint::of([
                'negotiated_quote_id' => $command->negotiatedQuoteId->value,
                'buyer' => (string) $command->buyer,
                'approval_flow_id' => $command->approvalFlowId?->value,
                'payment_terms_code' => $command->paymentTerms?->code,
            ]);

            return $this->receipts->once(
                self::CONVERT_SCOPE,
                $command->idempotencyKey,
                $fingerprint,
                $work,
                static fn (ConvertedQuoteOrderView $view): array => [
                    'negotiated_quote_id' => $view->negotiatedQuoteId->value,
                    'order_ref' => $view->orderRef,
                    'order_number' => $view->orderNumber,
                    'credit_hold_id' => $view->creditHoldId?->value,
                ],
                fn (array $stored): ConvertedQuoteOrderView => $this->rehydrateConvertedReceipt($stored),
            );
        });
    }

    #[Override]
    public function findRequest(QuoteRequestId $id): ?QuoteRequestView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_quote_requests'))
            ->where('id', $id->value)
            ->first();

        return $row === null ? null : self::hydrateRequest($row);
    }

    #[Override]
    public function getRequest(QuoteRequestId $id): QuoteRequestView
    {
        return $this->findRequest($id) ?? throw new QuoteNotFound('quote_request', $id->value);
    }

    #[Override]
    public function findStaged(StagedQuoteId $id): ?StagedQuoteView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_staged_quotes'))
            ->where('id', $id->value)
            ->first();

        return $row === null ? null : self::hydrateStaged($row);
    }

    #[Override]
    public function getStaged(StagedQuoteId $id): StagedQuoteView
    {
        return $this->findStaged($id) ?? throw new QuoteNotFound('staged_quote', $id->value);
    }

    #[Override]
    public function findNegotiated(NegotiatedQuoteId $id): ?NegotiatedQuoteView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_negotiated_quotes'))
            ->where('id', $id->value)
            ->first();

        return $row === null ? null : self::hydrateNegotiated($row);
    }

    #[Override]
    public function getNegotiated(NegotiatedQuoteId $id): NegotiatedQuoteView
    {
        return $this->findNegotiated($id) ?? throw new QuoteNotFound('negotiated_quote', $id->value);
    }

    private function executeConvert(ConvertQuoteToOrder $command): ConvertedQuoteOrderView
    {
        $negotiatedTable = $this->tables->table('b2b_negotiated_quotes');
        $row = $this->db()
            ->table($negotiatedTable)
            ->where('id', $command->negotiatedQuoteId->value)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new QuoteNotFound('negotiated_quote', $command->negotiatedQuoteId->value);
        }

        $quote = self::hydrateNegotiated($row);

        if ($quote->status === NegotiatedQuoteStatus::Converted) {
            throw new QuoteAlreadyConverted($quote->id, $quote->convertedOrderRef ?? 'order:unknown');
        }

        if ($quote->status !== NegotiatedQuoteStatus::Sent) {
            throw new InvalidQuoteState($quote->id->value, $quote->status->value, 'only sent quotes may be converted to an order');
        }

        $instant = $this->clock->now();

        if ($quote->isExpiredAt($instant)) {
            $this->markExpired($quote->id, $instant);
            throw new QuoteExpired($quote->id, $quote->validUntil);
        }

        $evaluation = $this->approvalFlows->evaluate(new EvaluateApproval(
            businessUnitId: $quote->businessUnitId,
            buyer: $command->buyer,
            amount: $quote->lockedTotal,
        ));

        $resolvedFlowId = $command->approvalFlowId;

        if ($evaluation->requiresApproval) {
            $flow = $resolvedFlowId !== null
                ? $this->approvalFlows->get($resolvedFlowId)
                : $this->approvalFlows->findLatestForSubject('negotiated_quote:'.$quote->id->value);

            if ($flow === null) {
                throw new ApprovalRequired($quote->businessUnitId, $quote->lockedTotal, $evaluation->reasons);
            }

            if (! $flow->businessUnitId->equals($quote->businessUnitId)) {
                throw new InvalidCommand('approvalFlowId', 'approval flow belongs to a different business unit', 'b2b.invalid_approval_flow');
            }

            if ($flow->status !== ApprovalStatus::Approved) {
                throw new ApprovalNotGranted($flow->id, $flow->status);
            }

            if ($flow->amount->compare($quote->lockedTotal) < 0) {
                throw new ApprovalRequired($quote->businessUnitId, $quote->lockedTotal, ['approved_amount_below_quote_total']);
            }

            $resolvedFlowId = $flow->id;
        }

        $effectiveTerms = $command->paymentTerms ?? $quote->paymentTerms;
        $schedule = null;
        $creditHoldId = null;

        if ($effectiveTerms !== null) {
            $plans = $this->collectSellingPlans($quote->lines);
            $validation = $this->creditValidator->validate(new ValidateCreditTerms(
                businessUnitId: $quote->businessUnitId,
                paymentTerms: $effectiveTerms,
                orderTotal: $quote->lockedTotal,
                sellingPlans: $plans,
                issuedAt: $instant,
            ));
            $schedule = $validation->schedule;
        }

        $byLineId = [];
        $bySellableRef = [];
        foreach ($quote->lines as $line) {
            $byLineId[$line->lineId] = $line->lockedUnitPrice;
            $bySellableRef[(string) $line->sellable->ref] = $line->lockedUnitPrice;
        }

        $actor = $command->actor ?? ActorRef::of($command->buyer->type, $command->buyer->id);
        $placementPort = $this->container->make(OrderPlacementPort::class);
        $placed = $this->lockedPriceScope->runWithLocks(
            byLineId: $byLineId,
            bySellableRef: $bySellableRef,
            priceSource: 'b2b.negotiated_quote:'.$quote->id->value,
            callback: static fn () => $placementPort->placeLockedQuote(new PlaceLockedQuoteOrder(
                negotiatedQuoteId: $quote->id,
                businessUnitId: $quote->businessUnitId,
                context: $quote->context->withAt($instant),
                lines: $quote->lines,
                lockedTotals: $quote->lockedTotals,
                paymentTerms: $effectiveTerms,
                paymentSchedule: $schedule,
                shippingAddress: $quote->shippingAddress,
                billingAddress: $quote->billingAddress,
                deliveryMethod: $quote->deliveryMethod,
                codes: $quote->codes,
                actor: $actor,
                placedAt: $instant,
            )),
        );

        if ($schedule !== null && $schedule->deferredAmount->isPositive()) {
            $hold = $this->creditAccounts->reserve(new ReserveCredit(
                businessUnitId: $quote->businessUnitId,
                reference: $placed->orderRef,
                amount: $schedule->deferredAmount,
                dueAt: $schedule->finalDueAt,
            ));
            $creditHoldId = $hold->id;
        }

        $now = PgTimestamp::format($instant);
        $this->db()
            ->table($negotiatedTable)
            ->where('id', $quote->id->value)
            ->update([
                'status' => NegotiatedQuoteStatus::Converted->value,
                'payment_terms' => PaymentTermsCodec::encode($effectiveTerms),
                'approval_flow_id' => $resolvedFlowId?->value,
                'converted_order_ref' => $placed->orderRef,
                'converted_at' => $now,
                'updated_at' => $now,
            ]);

        $this->db()
            ->table($this->tables->table('b2b_quote_requests'))
            ->where('id', $quote->quoteRequestId->value)
            ->update([
                'status' => QuoteRequestStatus::Converted->value,
                'updated_at' => $now,
            ]);

        $this->outbox->record(IntegrationEvent::of(
            type: 'b2b.quote_converted',
            aggregateRef: 'negotiated_quote:'.$quote->id->value,
            payload: [
                'negotiated_quote_id' => $quote->id->value,
                'quote_request_id' => $quote->quoteRequestId->value,
                'business_unit_id' => $quote->businessUnitId->value,
                'order_ref' => $placed->orderRef,
                'order_number' => $placed->orderNumber,
                'locked_total_minor' => $quote->lockedTotal->amountMinor,
                'currency' => $quote->lockedTotal->currency->code,
                'payment_terms_code' => $effectiveTerms?->code,
                'due_now_minor' => $schedule?->dueNowAmount->amountMinor,
                'deferred_minor' => $schedule?->deferredAmount->amountMinor,
                'credit_hold_id' => $creditHoldId?->value,
                'approval_flow_id' => $resolvedFlowId?->value,
            ],
            occurredAt: $instant,
        ));

        $this->events->dispatch(new NegotiatedQuoteConverted(
            negotiatedQuoteId: $quote->id,
            businessUnitId: $quote->businessUnitId,
            orderRef: $placed->orderRef,
        ));

        return new ConvertedQuoteOrderView(
            negotiatedQuoteId: $quote->id,
            orderRef: $placed->orderRef,
            orderNumber: $placed->orderNumber,
            businessUnitId: $quote->businessUnitId,
            lines: $quote->lines,
            lockedTotals: $quote->lockedTotals,
            paymentTerms: $effectiveTerms,
            paymentSchedule: $schedule,
            creditHoldId: $creditHoldId,
            approvalFlowId: $resolvedFlowId,
            convertedAt: $instant,
        );
    }

    /**
     * @param  list<StagedLineOverride>  $overrides
     * @return array{0: list<StagedQuoteLineView>, 1: QuoteTotals}
     */
    private function priceStagedLines(QuoteRequestView $request, array $overrides, ?PaymentTerms $terms): array
    {
        $overrideByLine = [];
        foreach ($overrides as $override) {
            if ($override->negotiatedUnitPrice !== null && ! $override->negotiatedUnitPrice->currency->equals($request->context->currency)) {
                throw new InvalidCommand('negotiatedUnitPrice', 'currency must match quote currency', 'b2b.currency_mismatch');
            }
            $overrideByLine[$override->lineId] = $override;
        }

        $refs = array_map(static fn (QuoteRequestLineView $l): SellableRef => $l->sellable, $request->lines);
        $snapshots = $this->sellables->resolveMany($refs, $request->context);

        $inputLines = [];
        $byLineId = [];
        $bySellableRef = [];
        $plansByLine = [];

        foreach ($request->lines as $reqLine) {
            $snapshot = $snapshots[(string) $reqLine->sellable];
            $override = $overrideByLine[$reqLine->lineId] ?? null;
            $qty = $override instanceof StagedLineOverride && $override->quantity !== null
                ? $override->quantity
                : $reqLine->quantity;

            $effectivePlan = $reqLine->sellingPlan ?? SellingPlanSummary::fromSellableAttributes($snapshot->attributes);
            $plansByLine[$reqLine->lineId] = $effectivePlan;

            if ($terms !== null && $effectivePlan?->isRecurring() === true) {
                throw new RecurringSellingPlanIncompatibleWithPaymentTerms($terms->code, $effectivePlan->code);
            }

            $inputLines[] = new QuoteLineInput($reqLine->lineId, $snapshot, $qty);

            if ($override?->negotiatedUnitPrice !== null) {
                $byLineId[$reqLine->lineId] = $override->negotiatedUnitPrice;
                $bySellableRef[(string) $reqLine->sellable] = $override->negotiatedUnitPrice;
            }
        }

        $delivery = null;
        if ($request->deliveryMethod !== null && $request->shippingAddress !== null) {
            $weight = 0;
            foreach ($inputLines as $il) {
                if ($il->sellable->requiresShipping) {
                    $weight += ($il->sellable->weightGrams ?? 0) * $il->quantity;
                }
            }
            $delivery = new DeliverySelection($request->deliveryMethod, $request->shippingAddress, new Parcel($weight, Money::zero($request->context->currency)));
        }

        $quoteInput = new QuoteInput($request->context, $inputLines, $request->codes, $delivery);

        $baselineResult = $this->quotePipeline->run($quoteInput);
        $catalogPrices = [];
        foreach ($baselineResult->lines as $bLine) {
            $catalogPrices[$bLine->lineId] = $bLine->unitPrice;
        }

        $stagedResult = $byLineId === []
            ? $baselineResult
            : $this->lockedPriceScope->runWithLocks(
                byLineId: $byLineId,
                bySellableRef: $bySellableRef,
                priceSource: 'b2b.staged_quote:'.$request->id->value,
                callback: fn (): QuoteResult => $this->quotePipeline->run($quoteInput),
            );

        $stagedLines = [];
        foreach ($stagedResult->lines as $qLine) {
            if ($qLine->isGift) {
                continue;
            }

            $stagedLines[] = new StagedQuoteLineView(
                lineId: $qLine->lineId,
                sellable: $qLine->sellable,
                quantity: $qLine->quantity,
                catalogUnitPrice: $catalogPrices[$qLine->lineId] ?? $qLine->unitPrice,
                stagedUnitPrice: $qLine->unitPrice,
                subtotal: $qLine->subtotal,
                taxTotal: self::sumLineTaxes($qLine, $request->context->currency),
                total: $qLine->total,
                taxIncluded: $qLine->taxIncluded,
                sellingPlan: $plansByLine[$qLine->lineId] ?? null,
            );
        }

        return [$stagedLines, $stagedResult->totals];
    }

    private static function sumLineTaxes(QuotedLine $line, Currency $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($line->taxLines as $tax) {
            $sum = $sum->plus($tax->amount);
        }

        return $sum;
    }

    /**
     * @param  list<NegotiatedQuoteLineView>  $lines
     * @return list<SellingPlanSummary>
     */
    private function collectSellingPlans(array $lines): array
    {
        $plans = [];
        foreach ($lines as $line) {
            $plan = $line->sellingPlan ?? SellingPlanSummary::fromSellableAttributes($line->sellable->attributes);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    private function markExpired(NegotiatedQuoteId $id, DateTimeImmutable $instant): void
    {
        $this->db()
            ->table($this->tables->table('b2b_negotiated_quotes'))
            ->where('id', $id->value)
            ->update([
                'status' => NegotiatedQuoteStatus::Expired->value,
                'updated_at' => PgTimestamp::format($instant),
            ]);
    }

    /** @param array<array-key, mixed> $stored */
    private function rehydrateConvertedReceipt(array $stored): ConvertedQuoteOrderView
    {
        $quoteId = isset($stored['negotiated_quote_id']) && is_string($stored['negotiated_quote_id'])
            ? NegotiatedQuoteId::fromString($stored['negotiated_quote_id'])
            : throw new LogicException('Missing negotiated_quote_id in receipt.');
        $orderRef = isset($stored['order_ref']) && is_string($stored['order_ref']) ? $stored['order_ref'] : 'order:unknown';
        $orderNumber = isset($stored['order_number']) && is_string($stored['order_number']) ? $stored['order_number'] : 'B2B';
        $creditHoldId = isset($stored['credit_hold_id']) && is_string($stored['credit_hold_id'])
            ? CreditHoldId::fromString($stored['credit_hold_id'])
            : null;

        $quote = $this->getNegotiated($quoteId);

        return new ConvertedQuoteOrderView(
            negotiatedQuoteId: $quote->id,
            orderRef: $orderRef,
            orderNumber: $orderNumber,
            businessUnitId: $quote->businessUnitId,
            lines: $quote->lines,
            lockedTotals: $quote->lockedTotals,
            paymentTerms: $quote->paymentTerms,
            paymentSchedule: $quote->paymentSchedule,
            creditHoldId: $creditHoldId,
            approvalFlowId: $quote->approvalFlowId,
            convertedAt: $quote->convertedAt ?? $this->clock->now(),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }

    private static function hydrateRequest(object $row): QuoteRequestView
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $buyer = CustomerRef::fromString(Rows::string($row, 'buyer_ref'));
        $createdAt = B2bCodec::parseDate(Rows::string($row, 'created_at'));
        $context = new CommerceContext(
            channel: ChannelId::fromString(Rows::string($row, 'channel_id')),
            currency: $currency,
            locale: Rows::string($row, 'locale'),
            customer: $buyer,
            customerGroups: B2bCodec::decodeStrings(Rows::string($row, 'customer_groups')),
            at: $createdAt,
        );

        return new QuoteRequestView(
            id: QuoteRequestId::fromString(Rows::string($row, 'id')),
            number: Rows::string($row, 'number'),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            buyer: $buyer,
            context: $context,
            sourceCartId: Rows::nullableString($row, 'source_cart_id'),
            lines: QuoteLineCodec::decodeRequestLines(Rows::string($row, 'lines'), $currency),
            requestedPaymentTerms: PaymentTermsCodec::decode(Rows::nullableString($row, 'requested_payment_terms')),
            purchaseOrderNumber: Rows::nullableString($row, 'purchase_order_number'),
            buyerNote: Rows::nullableString($row, 'buyer_note'),
            shippingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'shipping_address')),
            billingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'billing_address')),
            deliveryMethod: Rows::nullableString($row, 'delivery_method'),
            codes: B2bCodec::decodeStrings(Rows::string($row, 'codes')),
            status: QuoteRequestStatus::from(Rows::string($row, 'status')),
            revision: Rows::int($row, 'revision'),
            createdAt: $createdAt,
            updatedAt: B2bCodec::parseDate(Rows::string($row, 'updated_at')),
        );
    }

    private static function hydrateStaged(object $row): StagedQuoteView
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $totals = new QuoteTotals(
            subtotal: Money::ofMinor(Rows::int($row, 'subtotal_minor'), $currency),
            discountTotal: Money::ofMinor(Rows::int($row, 'discount_total_minor'), $currency),
            deliveryTotal: Money::ofMinor(Rows::int($row, 'delivery_total_minor'), $currency),
            taxTotal: Money::ofMinor(Rows::int($row, 'tax_total_minor'), $currency),
            total: Money::ofMinor(Rows::int($row, 'total_minor'), $currency),
        );

        return new StagedQuoteView(
            id: StagedQuoteId::fromString(Rows::string($row, 'id')),
            quoteRequestId: QuoteRequestId::fromString(Rows::string($row, 'quote_request_id')),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            revision: Rows::int($row, 'revision'),
            status: StagedQuoteStatus::from(Rows::string($row, 'status')),
            sellerActor: ActorRef::fromString(Rows::string($row, 'seller_actor_ref')),
            lineOverrides: QuoteLineCodec::decodeOverrides(Rows::string($row, 'line_overrides'), $currency),
            lines: QuoteLineCodec::decodeStagedLines(Rows::string($row, 'priced_lines'), $currency),
            totals: $totals,
            paymentTerms: PaymentTermsCodec::decode(Rows::nullableString($row, 'payment_terms')),
            validUntil: B2bCodec::parseDate(Rows::string($row, 'valid_until')),
            sellerNote: Rows::nullableString($row, 'seller_note'),
            createdAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
            updatedAt: B2bCodec::parseDate(Rows::string($row, 'updated_at')),
        );
    }

    private static function hydrateNegotiated(object $row): NegotiatedQuoteView
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $buyer = CustomerRef::fromString(Rows::string($row, 'buyer_ref'));
        $sentAt = B2bCodec::parseDate(Rows::string($row, 'sent_at'));
        $context = new CommerceContext(
            channel: ChannelId::fromString(Rows::string($row, 'channel_id')),
            currency: $currency,
            locale: Rows::string($row, 'locale'),
            customer: $buyer,
            customerGroups: B2bCodec::decodeStrings(Rows::string($row, 'customer_groups')),
            at: $sentAt,
        );
        $totals = new QuoteTotals(
            subtotal: Money::ofMinor(Rows::int($row, 'subtotal_minor'), $currency),
            discountTotal: Money::ofMinor(Rows::int($row, 'discount_total_minor'), $currency),
            deliveryTotal: Money::ofMinor(Rows::int($row, 'delivery_total_minor'), $currency),
            taxTotal: Money::ofMinor(Rows::int($row, 'tax_total_minor'), $currency),
            total: Money::ofMinor(Rows::int($row, 'total_minor'), $currency),
        );
        $terms = PaymentTermsCodec::decode(Rows::nullableString($row, 'payment_terms'));
        $convertedAt = B2bCodec::parseNullableDate(Rows::nullableString($row, 'converted_at'));
        $schedule = $terms?->schedule($totals->total, $convertedAt ?? $sentAt);
        $approvalId = Rows::nullableString($row, 'approval_flow_id');

        return new NegotiatedQuoteView(
            id: NegotiatedQuoteId::fromString(Rows::string($row, 'id')),
            quoteRequestId: QuoteRequestId::fromString(Rows::string($row, 'quote_request_id')),
            stagedQuoteId: StagedQuoteId::fromString(Rows::string($row, 'staged_quote_id')),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            buyer: $buyer,
            revision: Rows::int($row, 'revision'),
            status: NegotiatedQuoteStatus::from(Rows::string($row, 'status')),
            context: $context,
            lines: QuoteLineCodec::decodeNegotiatedLines(Rows::string($row, 'locked_lines'), $currency),
            lockedTotals: $totals,
            paymentTerms: $terms,
            paymentSchedule: $schedule,
            shippingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'shipping_address')),
            billingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'billing_address')),
            deliveryMethod: Rows::nullableString($row, 'delivery_method'),
            codes: B2bCodec::decodeStrings(Rows::string($row, 'codes')),
            validUntil: B2bCodec::parseDate(Rows::string($row, 'valid_until')),
            sellerNote: Rows::nullableString($row, 'seller_note'),
            renegotiationComment: Rows::nullableString($row, 'renegotiation_comment'),
            approvalFlowId: $approvalId === null ? null : ApprovalFlowId::fromString($approvalId),
            convertedOrderRef: Rows::nullableString($row, 'converted_order_ref'),
            sentAt: $sentAt,
            convertedAt: $convertedAt,
        );
    }
}
