<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Concurrency\Support;

use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;

/** Carts and orders of the shop for the concurrency suite, built in the parent through the public API before forking. */
final class Checkout
{
    /**
     * A cart of `$customer` shipping to Moscow by courier, quoted: the workers place it at this total.
     *
     * @param  list<array{SellableRef, int}>  $lines
     * @return array{CartId, Money}
     */
    public static function cart(ShopWorld $world, array $lines, string $customer, bool $coupon = false): array
    {
        $cart = Sales::carts()->create(new CreateCart(CommerceContext::for($world->channel, Currency::of('RUB'), 'ru')))->id;
        Sales::carts()->setCustomer(new SetCartCustomer($cart, CustomerRef::of('crm', $customer)));

        foreach ($lines as [$sellable, $quantity]) {
            Sales::carts()->addLine(new AddCartLine($cart, $sellable, $quantity));
        }

        Sales::carts()->setAddress(new SetCartAddress($cart, AddressRole::Shipping, new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Buyer', '+70000000000')));
        Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));

        if ($coupon) {
            Sales::carts()->enterCode(new EnterCode($cart, ShopWorld::COUPON));
        }

        return [$cart, Sales::carts()->quote($cart)->total];
    }

    /** @param  array{CartId, Money}  $cart */
    public static function place(array $cart): OrderView
    {
        return Sales::checkout()->place(new PlaceOrder($cart[0], $cart[1]));
    }

    /** Pays a placed order in full through the manual gateway. */
    public static function pay(OrderView $order): void
    {
        $payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
        Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'capture-'.$order->id->value, PaymentEventType::Captured, paymentReference: $payment->reference));
    }
}
