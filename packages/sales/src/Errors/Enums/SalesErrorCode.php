<?php

declare(strict_types=1);

namespace Commerz\Sales\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-sales`.
 */
#[Api(since: '0.1')]
enum SalesErrorCode: string implements ErrorCode
{
    case CaptureExceedsAuthorization = 'sales.capture_exceeds_authorization';
    case CartAlreadyPlaced = 'sales.cart_already_placed';
    case CartChanged = 'sales.cart_changed';
    case CartEmpty = 'sales.cart_empty';
    case CartLineNotFound = 'sales.cart_line_not_found';
    case CartNotFound = 'sales.cart_not_found';
    case CartNotOpen = 'sales.cart_not_open';
    case CurrencyMismatch = 'sales.currency_mismatch';
    case DeliveryRequired = 'sales.delivery_required';
    case DuplicateOrderNumber = 'sales.duplicate_order_number';
    case FulfillmentAlreadyShipped = 'sales.fulfillment_already_shipped';
    case FulfillmentNotBookable = 'sales.fulfillment_not_bookable';
    case FulfillmentNotFound = 'sales.fulfillment_not_found';
    case FulfillmentNotShipped = 'sales.fulfillment_not_shipped';
    case FulfillmentOrderNotFound = 'sales.fulfillment_order_not_found';
    case GatewayCapabilityUnsupported = 'sales.gateway_capability_unsupported';
    case InvalidCode = 'sales.invalid_code';
    case InvalidDeliveryMethod = 'sales.invalid_delivery_method';
    case InvalidFulfillment = 'sales.invalid_fulfillment';
    case InvalidFulfillmentOrderState = 'sales.invalid_fulfillment_order_state';
    case InvalidGatewayPayment = 'sales.invalid_gateway_payment';
    case InvalidGatewayRefund = 'sales.invalid_gateway_refund';
    case InvalidGatewayResult = 'sales.invalid_gateway_result';
    case InvalidGroup = 'sales.invalid_group';
    case InvalidLimit = 'sales.invalid_limit';
    case InvalidLines = 'sales.invalid_lines';
    case InvalidOrderChange = 'sales.invalid_order_change';
    case InvalidOrderNumber = 'sales.invalid_order_number';
    case InvalidPayment = 'sales.invalid_payment';
    case InvalidPaymentEvent = 'sales.invalid_payment_event';
    case InvalidPaymentSessionState = 'sales.invalid_payment_session_state';
    case InvalidQuantity = 'sales.invalid_quantity';
    case InvalidRefund = 'sales.invalid_refund';
    case InvalidReturn = 'sales.invalid_return';
    case LineNotFound = 'sales.line_not_found';
    case OperationInsideTransaction = 'sales.operation_inside_transaction';
    case OrderChangeLineLocked = 'sales.order_change_line_locked';
    case OrderChangeNotFound = 'sales.order_change_not_found';
    case OrderChangeNotOpen = 'sales.order_change_not_open';
    case OrderChangeVersionConflict = 'sales.order_change_version_conflict';
    case OrderNotCancellable = 'sales.order_not_cancellable';
    case OrderNotEditable = 'sales.order_not_editable';
    case OrderNotFound = 'sales.order_not_found';
    case OrderNotFulfillable = 'sales.order_not_fulfillable';
    case OrderNotPayable = 'sales.order_not_payable';
    case PaymentCollectionNotFound = 'sales.payment_collection_not_found';
    case PaymentDeclined = 'sales.payment_declined';
    case PaymentExceedsBalance = 'sales.payment_exceeds_balance';
    case PaymentInProgress = 'sales.payment_in_progress';
    case PaymentNotFound = 'sales.payment_not_found';
    case PaymentOutcomeUnknown = 'sales.payment_outcome_unknown';
    case PaymentSessionNotFound = 'sales.payment_session_not_found';
    case QuantityExceedsOutstanding = 'sales.quantity_exceeds_outstanding';
    case QuoteChanged = 'sales.quote_changed';
    case RefundExceedsBalance = 'sales.refund_exceeds_balance';
    case RefundInProgress = 'sales.refund_in_progress';
    case RefundNotFound = 'sales.refund_not_found';
    case RefundOutcomeUnknown = 'sales.refund_outcome_unknown';
    case RestockExceedsReceived = 'sales.restock_exceeds_received';
    case ReturnExceedsFulfilled = 'sales.return_exceeds_fulfilled';
    case ReturnNotFound = 'sales.return_not_found';
    case ReturnNotOpen = 'sales.return_not_open';
    case SellableNotPurchasable = 'sales.sellable_not_purchasable';
    case ShippingAddressRequired = 'sales.shipping_address_required';
    case TooManyCartLines = 'sales.too_many_cart_lines';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
