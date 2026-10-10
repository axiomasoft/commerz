<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Commerz\Attributes\Api;
use Commerz\Commerz;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Testkit\Fakes\FakeCustomers;
use Commerz\Testkit\Fakes\FakeDelivery;
use Commerz\Testkit\Fakes\FakePrices;
use Commerz\Testkit\Fakes\FakePromotionUsage;
use Commerz\Testkit\Fakes\FakeSellableRouter;
use Commerz\Testkit\Fakes\FakeSellables;
use Commerz\Testkit\Fakes\FakeStock;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * Swaps every core port for an in-memory fake: `$commerce = Commerce::fake();` then arrange through
 * `$commerce->stock->limit(...)`, `$commerce->sellables->add(...)` and assert on what the fakes recorded.
 * Quote stages and the `Commerz` root see the fakes even when they were resolved before.
 */
#[Api(since: '0.1')]
final readonly class Commerce
{
    public function __construct(
        public FakeSellables $sellables,
        public FakeStock $stock,
        public FakePrices $prices,
        public FakePromotionUsage $promotions,
        public FakeDelivery $delivery,
        public FakeCustomers $customers,
    ) {}

    public static function fake(?Container $container = null): self
    {
        $container ??= Container::getInstance();
        $fakes = new self(new FakeSellables, new FakeStock, new FakePrices, new FakePromotionUsage, new FakeDelivery, new FakeCustomers);

        $container->instance(Sellables::class, new FakeSellableRouter($fakes->sellables));
        $container->instance(StockAvailability::class, $fakes->stock);
        $container->instance(StockReservations::class, $fakes->stock);
        $container->instance(StockReturns::class, $fakes->stock);
        $container->instance(PriceResolver::class, $fakes->prices);
        $container->instance(PromotionUsage::class, $fakes->promotions);
        $container->instance(DeliveryOptions::class, $fakes->delivery);
        $container->instance(ShipmentBooking::class, $fakes->delivery);
        $container->instance(CustomerDirectory::class, $fakes->customers);

        $container->forgetInstance(Commerz::class);
        Facade::clearResolvedInstance(Commerz::class);

        return $fakes;
    }
}
