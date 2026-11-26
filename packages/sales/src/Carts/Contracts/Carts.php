<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Contracts;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Data\ClearDelivery;
use Commerz\Sales\Carts\Data\ClearGroupDelivery;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\ListCarts;
use Commerz\Sales\Carts\Data\RemoveCartLine;
use Commerz\Sales\Carts\Data\RemoveCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SelectGroupDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Data\SetCartLineQuantity;
use Commerz\Sales\Carts\Data\SetCartPickupPoint;
use Commerz\Sales\Carts\Data\SplitDeliveryQuote;
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotOpen;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Carts\Exceptions\TooManyCartLines;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Packman\Attributes\Api;

/**
 * Carts, and the drafts of a CRM, which are carts of kind `draft`. A cart sells in the channel, currency and locale it
 * was created with; every change moves its `version` on, and a converted cart is no longer changed.
 */
#[Api(since: '0.1')]
interface Carts
{
    /**
     * @throws ChannelNotFound|ChannelArchived|CurrencyNotAllowed
     */
    public function create(CreateCart $command): CartView;

    /**
     * @throws CartNotFound|CartNotOpen
     * @throws SellableUnavailable the sellable's owner does not know it
     * @throws SellableNotPurchasable
     * @throws TooManyCartLines
     * @throws InvalidCommand sales.invalid_quantity when the line would exceed `max_quantity`
     */
    public function addLine(AddCartLine $command): CartView;

    /**
     * @throws CartNotFound|CartNotOpen|CartLineNotFound
     * @throws InvalidCommand sales.invalid_quantity
     */
    public function setLineQuantity(SetCartLineQuantity $command): CartView;

    /** @throws CartNotFound|CartNotOpen|CartLineNotFound */
    public function removeLine(RemoveCartLine $command): CartView;

    /** A code already entered changes nothing. @throws CartNotFound|CartNotOpen */
    public function enterCode(EnterCode $command): CartView;

    /** A code that was not entered changes nothing. @throws CartNotFound|CartNotOpen */
    public function removeCode(RemoveCode $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function setCustomer(SetCartCustomer $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function setAddress(SetCartAddress $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function setPickupPoint(SetCartPickupPoint $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function selectDelivery(SelectDelivery $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function clearDelivery(ClearDelivery $command): CartView;

    /**
     * Assigns a delivery method (and optional address or PUDO/BOPIS destination) to a disjoint group of cart lines.
     *
     * @throws CartNotFound|CartNotOpen|CartLineNotFound
     */
    public function selectGroupDelivery(SelectGroupDelivery $command): CartView;

    /** @throws CartNotFound|CartNotOpen */
    public function clearGroupDelivery(ClearGroupDelivery $command): CartView;

    /** @throws CartNotFound */
    public function find(CartId $id): CartView;

    /** @return Page<CartView> */
    public function list(ListCarts $query): Page;

    /**
     * Prices the cart now: the one calculation the placement of the order checks its total against. An empty cart
     * quotes to zero.
     *
     * @throws CartNotFound
     * @throws ShippingAddressRequired a delivery method is selected and there is no shipping address or pickup point
     * @throws SellableUnavailable
     * @throws QuoteFailed
     */
    public function quote(CartId $id): QuoteResult;

    /**
     * Prices each split-delivery line group and combines their delivery charges with the cart's goods quote.
     *
     * @throws CartNotFound
     * @throws ShippingAddressRequired
     * @throws SellableUnavailable
     * @throws QuoteFailed
     */
    public function quoteSplitDelivery(CartId $id): SplitDeliveryQuote;

    /**
     * The delivery methods that serve the cart's shipping address for the goods that ship; none when no line ships.
     *
     * @return list<DeliveryOption>
     *
     * @throws CartNotFound
     * @throws ShippingAddressRequired
     * @throws SellableUnavailable
     * @throws QuoteFailed
     */
    public function deliveryOptions(CartId $id): array;

    /**
     * Available delivery options for a specific group of cart lines (for split delivery / multi-origin / BOPIS).
     *
     * @param  list<string>  $lineIds
     * @return list<DeliveryOption>
     *
     * @throws CartNotFound|CartLineNotFound
     * @throws ShippingAddressRequired
     * @throws SellableUnavailable
     * @throws QuoteFailed
     */
    public function groupDeliveryOptions(
        CartId $id,
        array $lineIds,
        ?Address $destination = null,
        ?PickupPointDestination $pickupPoint = null,
    ): array;
}
