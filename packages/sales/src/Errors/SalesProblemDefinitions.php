<?php

declare(strict_types=1);

namespace Commerz\Sales\Errors;

use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-sales` error codes.
 */
#[Api(since: '0.1')]
final readonly class SalesProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(SalesErrorCode::CartAlreadyPlaced, 409, 'Cart Already Placed', ErrorCategory::Conflict, ['cart', 'order']),
            $this->define(SalesErrorCode::CartChanged, 409, 'Cart Changed During Checkout', ErrorCategory::Conflict, ['cart', 'expected_version', 'actual_version']),
            $this->define(SalesErrorCode::CartEmpty, 422, 'Cart Is Empty', ErrorCategory::Validation, ['cart']),
            $this->define(SalesErrorCode::CartLineNotFound, 404, 'Cart Line Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::CartNotFound, 404, 'Cart Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::CartNotOpen, 409, 'Cart Not Open', ErrorCategory::Conflict, ['cart', 'state']),
            $this->define(SalesErrorCode::CurrencyMismatch, 422, 'Sales Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::DeliveryRequired, 422, 'Delivery Method Required', ErrorCategory::Validation, ['cart']),
            $this->define(SalesErrorCode::DuplicateOrderNumber, 409, 'Duplicate Order Number', ErrorCategory::Conflict, ['store', 'number']),
            $this->define(SalesErrorCode::FulfillmentAlreadyShipped, 409, 'Fulfillment Already Shipped', ErrorCategory::Conflict, ['*']),
            $this->define(SalesErrorCode::FulfillmentNotBookable, 409, 'Fulfillment Not Bookable', ErrorCategory::Conflict, ['fulfillment', 'reason']),
            $this->define(SalesErrorCode::FulfillmentNotFound, 404, 'Fulfillment Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::FulfillmentNotShipped, 409, 'Fulfillment Not Shipped', ErrorCategory::Conflict, ['fulfillment']),
            $this->define(SalesErrorCode::InvalidCode, 422, 'Invalid Sales Code', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidDeliveryMethod, 422, 'Invalid Delivery Method', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidFulfillment, 422, 'Invalid Fulfillment Command', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidGatewayPayment, 422, 'Invalid Gateway Payment Request', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidGatewayRefund, 422, 'Invalid Gateway Refund Request', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidGatewayResult, 503, 'Invalid Payment Gateway Result', ErrorCategory::Unavailable, ['gateway', 'reason']),
            $this->define(SalesErrorCode::InvalidGroup, 422, 'Invalid Customer Group', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidLimit, 422, 'Invalid Batch Limit', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidLines, 422, 'Invalid Order Lines', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidOrderChange, 422, 'Invalid Order Change', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidOrderNumber, 422, 'Invalid Order Number', ErrorCategory::Validation, ['generator', 'number']),
            $this->define(SalesErrorCode::InvalidPayment, 422, 'Invalid Payment Request', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidPaymentEvent, 422, 'Invalid Payment Event', ErrorCategory::Validation, ['field', 'reason']),
            $this->define(SalesErrorCode::InvalidQuantity, 422, 'Invalid Line Quantity', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidRefund, 422, 'Invalid Refund Request', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::InvalidReturn, 422, 'Invalid Return Request', ErrorCategory::Validation, ['*']),
            $this->define(SalesErrorCode::LineNotFound, 404, 'Order Line Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::OperationInsideTransaction, 422, 'Operation Inside Database Transaction', ErrorCategory::Validation, ['operation']),
            $this->define(SalesErrorCode::OrderChangeLineLocked, 409, 'Order Line Locked Against Edit', ErrorCategory::Conflict, ['line', 'requested_quantity', 'locked_quantity']),
            $this->define(SalesErrorCode::OrderChangeNotFound, 404, 'Order Change Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::OrderChangeNotOpen, 409, 'Order Change Not Open', ErrorCategory::Conflict, ['change', 'status']),
            $this->define(SalesErrorCode::OrderChangeVersionConflict, 409, 'Order Change Version Conflict (ORDERS_ORDER_CHANGE_VERSION_CONFLICT)', ErrorCategory::Conflict, ['change', 'order', 'expected_version', 'actual_version', 'domain_code', 'invariant']),
            $this->define(SalesErrorCode::OrderNotCancellable, 409, 'Order Not Cancellable', ErrorCategory::Conflict, ['order', 'reason']),
            $this->define(SalesErrorCode::OrderNotEditable, 409, 'Order Not Editable', ErrorCategory::Conflict, ['order', 'state']),
            $this->define(SalesErrorCode::OrderNotFound, 404, 'Order Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::OrderNotFulfillable, 409, 'Order Not Fulfillable', ErrorCategory::Conflict, ['order', 'state']),
            $this->define(SalesErrorCode::OrderNotPayable, 409, 'Order Not Payable', ErrorCategory::Conflict, ['order', 'state']),
            $this->define(SalesErrorCode::PaymentDeclined, 409, 'Payment Declined By Gateway', ErrorCategory::Conflict, ['gateway', 'reason']),
            $this->define(SalesErrorCode::PaymentExceedsBalance, 409, 'Payment Exceeds Order Balance', ErrorCategory::Conflict, ['order', 'requested_minor', 'balance_minor', 'currency']),
            $this->define(SalesErrorCode::PaymentInProgress, 409, 'Payment Initiation In Progress', ErrorCategory::Conflict, ['payment']),
            $this->define(SalesErrorCode::PaymentNotFound, 404, 'Payment Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::PaymentOutcomeUnknown, 503, 'Payment Outcome Unknown', ErrorCategory::Unavailable, ['payment', 'gateway']),
            $this->define(SalesErrorCode::QuantityExceedsOutstanding, 409, 'Quantity Exceeds Outstanding', ErrorCategory::Conflict, ['line', 'requested', 'available']),
            $this->define(SalesErrorCode::QuoteChanged, 409, 'Quote Changed Before Checkout', ErrorCategory::Conflict, ['expected_minor', 'expected_currency', 'actual_minor', 'actual_currency']),
            $this->define(SalesErrorCode::RefundExceedsBalance, 409, 'Refund Exceeds Payment Balance', ErrorCategory::Conflict, ['order', 'requested_minor', 'refundable_minor', 'currency']),
            $this->define(SalesErrorCode::RefundInProgress, 409, 'Refund Issuance In Progress', ErrorCategory::Conflict, ['refund']),
            $this->define(SalesErrorCode::RefundNotFound, 404, 'Refund Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::RefundOutcomeUnknown, 503, 'Refund Outcome Unknown', ErrorCategory::Unavailable, ['refund', 'gateway']),
            $this->define(SalesErrorCode::RestockExceedsReceived, 409, 'Restock Exceeds Received Quantity', ErrorCategory::Conflict, ['line', 'restock', 'received']),
            $this->define(SalesErrorCode::ReturnExceedsFulfilled, 409, 'Return Exceeds Fulfilled Quantity', ErrorCategory::Conflict, ['line', 'requested', 'available']),
            $this->define(SalesErrorCode::ReturnNotFound, 404, 'Return Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(SalesErrorCode::ReturnNotOpen, 409, 'Return Not Open', ErrorCategory::Conflict, ['return', 'status']),
            $this->define(SalesErrorCode::SellableNotPurchasable, 422, 'Sellable Not Purchasable', ErrorCategory::Validation, ['sellable', 'reason']),
            $this->define(SalesErrorCode::ShippingAddressRequired, 422, 'Shipping Address Required', ErrorCategory::Validation, ['field', 'reason']),
            $this->define(SalesErrorCode::TooManyCartLines, 422, 'Too Many Cart Lines', ErrorCategory::Validation, ['field', 'reason']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        SalesErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
