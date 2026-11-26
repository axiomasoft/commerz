<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotOpen;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Carts\Exceptions\TooManyCartLines;
use Commerz\Sales\Checkout\Exceptions\CartAlreadyPlaced;
use Commerz\Sales\Checkout\Exceptions\CartChanged;
use Commerz\Sales\Checkout\Exceptions\CartEmpty;
use Commerz\Sales\Checkout\Exceptions\DeliveryRequired;
use Commerz\Sales\Checkout\Exceptions\DuplicateOrderNumber;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Commerz\Sales\Errors\SalesProblemDefinitions;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentAlreadyShipped;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotBookable;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotFound;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotShipped;
use Commerz\Sales\Fulfillments\Exceptions\OrderNotFulfillable;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Numbering\Exceptions\InvalidOrderNumber;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeLineLocked;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotFound;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotOpen;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\OrderChanges\Exceptions\OrderNotEditable;
use Commerz\Sales\Orders\Exceptions\OrderNotCancellable;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentInProgress;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use Commerz\Sales\Refunds\Exceptions\RefundExceedsBalance;
use Commerz\Sales\Refunds\Exceptions\RefundInProgress;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use Commerz\Sales\Returns\Exceptions\RestockExceedsReceived;
use Commerz\Sales\Returns\Exceptions\ReturnExceedsFulfilled;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Sales\Returns\Exceptions\ReturnNotOpen;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

it('covers every SalesErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new SalesProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(SalesErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on sales exceptions', function (CommerzException $e, SalesErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($e)->toBeInstanceOf(CodedException::class)
        ->and($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'cart line not found' => [new CartLineNotFound('cl1'), SalesErrorCode::CartLineNotFound, ErrorCategory::NotFound],
    'cart not found' => [new CartNotFound('c1'), SalesErrorCode::CartNotFound, ErrorCategory::NotFound],
    'cart not open' => [new CartNotOpen('c1', 'placed'), SalesErrorCode::CartNotOpen, ErrorCategory::Conflict],
    'sellable not purchasable' => [new SellableNotPurchasable(SellableRef::of('variant', 'v1')), SalesErrorCode::SellableNotPurchasable, ErrorCategory::Validation],
    'shipping address required' => [new ShippingAddressRequired, SalesErrorCode::ShippingAddressRequired, ErrorCategory::Validation],
    'too many cart lines' => [new TooManyCartLines(10), SalesErrorCode::TooManyCartLines, ErrorCategory::Validation],
    'cart already placed' => [new CartAlreadyPlaced('c1', 'o1'), SalesErrorCode::CartAlreadyPlaced, ErrorCategory::Conflict],
    'cart changed' => [new CartChanged('c1', 1, 2), SalesErrorCode::CartChanged, ErrorCategory::Conflict],
    'cart empty' => [new CartEmpty('c1'), SalesErrorCode::CartEmpty, ErrorCategory::Validation],
    'delivery required' => [new DeliveryRequired('c1'), SalesErrorCode::DeliveryRequired, ErrorCategory::Validation],
    'duplicate order number' => [new DuplicateOrderNumber('s1', 'ORD-1'), SalesErrorCode::DuplicateOrderNumber, ErrorCategory::Conflict],
    'quote changed' => [new QuoteChanged(Money::of('10.00', 'RUB'), Money::of('12.00', 'RUB')), SalesErrorCode::QuoteChanged, ErrorCategory::Conflict],
    'line not found' => [new LineNotFound('ol1'), SalesErrorCode::LineNotFound, ErrorCategory::NotFound],
    'operation inside transaction' => [new OperationInsideTransaction('sales.payments.initiate'), SalesErrorCode::OperationInsideTransaction, ErrorCategory::Validation],
    'quantity exceeds outstanding' => [new QuantityExceedsOutstanding('ol1', 3, 1), SalesErrorCode::QuantityExceedsOutstanding, ErrorCategory::Conflict],
    'fulfillment already shipped' => [new FulfillmentAlreadyShipped('f1', 'manual', 'TRK-1'), SalesErrorCode::FulfillmentAlreadyShipped, ErrorCategory::Conflict],
    'fulfillment not bookable' => [new FulfillmentNotBookable('f1', 'no_delivery'), SalesErrorCode::FulfillmentNotBookable, ErrorCategory::Conflict],
    'fulfillment not found' => [new FulfillmentNotFound('f1'), SalesErrorCode::FulfillmentNotFound, ErrorCategory::NotFound],
    'fulfillment not shipped' => [new FulfillmentNotShipped('f1'), SalesErrorCode::FulfillmentNotShipped, ErrorCategory::Conflict],
    'order not fulfillable' => [new OrderNotFulfillable('o1', 'cancelled'), SalesErrorCode::OrderNotFulfillable, ErrorCategory::Conflict],
    'invalid gateway result' => [new InvalidGatewayResult('manual', 'bad'), SalesErrorCode::InvalidGatewayResult, ErrorCategory::Unavailable],
    'payment declined' => [new PaymentDeclined('manual', 'declined'), SalesErrorCode::PaymentDeclined, ErrorCategory::Conflict],
    'invalid order number' => [new InvalidOrderNumber('sequence', '???'), SalesErrorCode::InvalidOrderNumber, ErrorCategory::Validation],
    'order not cancellable' => [new OrderNotCancellable('o1', 'completed'), SalesErrorCode::OrderNotCancellable, ErrorCategory::Conflict],
    'order not editable' => [new OrderNotEditable('o1', 'cancelled'), SalesErrorCode::OrderNotEditable, ErrorCategory::Conflict],
    'order change line locked' => [new OrderChangeLineLocked('ol1', 1, 2), SalesErrorCode::OrderChangeLineLocked, ErrorCategory::Conflict],
    'order change not found' => [new OrderChangeNotFound('oc1'), SalesErrorCode::OrderChangeNotFound, ErrorCategory::NotFound],
    'order change not open' => [new OrderChangeNotOpen('oc1', 'confirmed'), SalesErrorCode::OrderChangeNotOpen, ErrorCategory::Conflict],
    'order change version conflict' => [new OrderChangeVersionConflict('oc1', 'o1', 1, 2), SalesErrorCode::OrderChangeVersionConflict, ErrorCategory::Conflict],
    'order not found' => [new OrderNotFound('o1'), SalesErrorCode::OrderNotFound, ErrorCategory::NotFound],
    'invalid payment event' => [new InvalidPaymentEvent('amount', 'wrong currency'), SalesErrorCode::InvalidPaymentEvent, ErrorCategory::Validation],
    'order not payable' => [new OrderNotPayable('o1', 'cancelled'), SalesErrorCode::OrderNotPayable, ErrorCategory::Conflict],
    'payment exceeds balance' => [new PaymentExceedsBalance('o1', Money::of('20.00', Currency::of('RUB')), Money::of('10.00', 'RUB')), SalesErrorCode::PaymentExceedsBalance, ErrorCategory::Conflict],
    'payment in progress' => [new PaymentInProgress('p1'), SalesErrorCode::PaymentInProgress, ErrorCategory::Conflict],
    'payment not found' => [new PaymentNotFound('p1'), SalesErrorCode::PaymentNotFound, ErrorCategory::NotFound],
    'payment outcome unknown' => [new PaymentOutcomeUnknown('p1', 'manual'), SalesErrorCode::PaymentOutcomeUnknown, ErrorCategory::Unavailable],
    'refund exceeds balance' => [new RefundExceedsBalance('o1', Money::of('20.00', 'RUB'), Money::of('10.00', 'RUB')), SalesErrorCode::RefundExceedsBalance, ErrorCategory::Conflict],
    'refund in progress' => [new RefundInProgress('rf1'), SalesErrorCode::RefundInProgress, ErrorCategory::Conflict],
    'refund not found' => [new RefundNotFound('rf1'), SalesErrorCode::RefundNotFound, ErrorCategory::NotFound],
    'refund outcome unknown' => [new RefundOutcomeUnknown('rf1', 'manual'), SalesErrorCode::RefundOutcomeUnknown, ErrorCategory::Unavailable],
    'restock exceeds received' => [new RestockExceedsReceived('ol1', 3, 1), SalesErrorCode::RestockExceedsReceived, ErrorCategory::Conflict],
    'return exceeds fulfilled' => [new ReturnExceedsFulfilled('ol1', 3, 1), SalesErrorCode::ReturnExceedsFulfilled, ErrorCategory::Conflict],
    'return not found' => [new ReturnNotFound('rt1'), SalesErrorCode::ReturnNotFound, ErrorCategory::NotFound],
    'return not open' => [new ReturnNotOpen('rt1', 'received'), SalesErrorCode::ReturnNotOpen, ErrorCategory::Conflict],
]);
