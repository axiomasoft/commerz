<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Fulfillments\Contracts\FulfillmentOrders;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Payments\Contracts\PaymentCollections;
use Commerz\Sales\Payments\Contracts\PaymentMethodResolver;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Returns\Contracts\Returns;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;

/**
 * Root of the sales API: accessors of the module contracts. A module is resolved when it is first asked for, so the
 * ports one module needs (the stock of the checkout, say) are not required by a host that uses only another one.
 */
#[Api(since: '0.1')]
final readonly class Sales
{
    public function __construct(private Container $container) {}

    public function carts(): Carts
    {
        return $this->container->make(Carts::class);
    }

    public function checkout(): Checkout
    {
        return $this->container->make(Checkout::class);
    }

    public function orders(): Orders
    {
        return $this->container->make(Orders::class);
    }

    public function orderChanges(): OrderChanges
    {
        return $this->container->make(OrderChanges::class);
    }

    public function payments(): Payments
    {
        return $this->container->make(Payments::class);
    }

    public function paymentCollections(): PaymentCollections
    {
        return $this->container->make(PaymentCollections::class);
    }

    public function paymentMethods(): PaymentMethodResolver
    {
        return $this->container->make(PaymentMethodResolver::class);
    }

    public function fulfillments(): Fulfillments
    {
        return $this->container->make(Fulfillments::class);
    }

    public function fulfillmentOrders(): FulfillmentOrders
    {
        return $this->container->make(FulfillmentOrders::class);
    }

    public function returns(): Returns
    {
        return $this->container->make(Returns::class);
    }

    public function refunds(): Refunds
    {
        return $this->container->make(Refunds::class);
    }
}
