<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Contracts;

use Commerz\Errors\IdempotencyConflict;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Checkout\Exceptions\CartAlreadyPlaced;
use Commerz\Sales\Checkout\Exceptions\CartChanged;
use Commerz\Sales\Checkout\Exceptions\CartEmpty;
use Commerz\Sales\Checkout\Exceptions\DeliveryRequired;
use Commerz\Sales\Checkout\Exceptions\DuplicateOrderNumber;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Numbering\Exceptions\InvalidOrderNumber;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/** Turns a cart into an order. */
#[Api(since: '0.1')]
interface Checkout
{
    /**
     * Places the cart in one transaction (ORD-01, ORD-02): the snapshot of its quote becomes the order with a number,
     * the physical lines are reserved without a deadline, the applied promotions are counted and the cart is converted.
     * Any failure leaves nothing behind. A cart becomes at most one order: a repeat with the same idempotency key
     * returns that order, a repeat without one fails with `CartAlreadyPlaced`. Payment is a separate command.
     *
     * @throws CartNotFound
     * @throws CartEmpty
     * @throws CartAlreadyPlaced
     * @throws CartChanged the cart changed between its quote and its lock; quote it again
     * @throws QuoteChanged the cart no longer quotes to `expectedTotal`
     * @throws SellableUnavailable|SellableNotPurchasable
     * @throws ShippingAddressRequired|DeliveryRequired
     * @throws QuoteFailed
     * @throws ChannelNotFound|ChannelArchived|CurrencyNotAllowed
     * @throws InsufficientStock
     * @throws UsageLimitReached
     * @throws IdempotencyConflict the key was used for another cart, version or total
     * @throws DuplicateOrderNumber
     * @throws InvalidOrderNumber|UnsupportedContribution|InvalidConfiguration a misconfigured number generator or promotion usage
     */
    public function place(PlaceOrder $command): OrderView;
}
