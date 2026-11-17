<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Support;

use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Quotes\Contracts\QuoteStage;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;

/** What the full family binds to every cross-package port, as class names: the tests never import `Internal`. */
final class PortMatrix
{
    /** @var array<class-string, class-string> */
    public const array IMPLEMENTATIONS = [
        StockReservations::class => 'Commerz\Inventory\Reservations\Internal\DatabaseReservations',
        StockAvailability::class => 'Commerz\Inventory\Availability\Internal\DatabaseAvailability',
        StockReturns::class => 'Commerz\Inventory\Stock\Internal\DatabaseStockReturns',
        PriceResolver::class => 'Commerz\Pricing\Prices\Internal\DatabasePriceResolver',
        PromotionUsage::class => 'Commerz\Promotions\Usage\Internal\DatabasePromotionUsage',
        DeliveryOptions::class => 'Commerz\Shipping\Options\Internal\DatabaseShippingOptions',
        ShipmentBooking::class => 'Commerz\Shipping\Bookings\Internal\CarrierShipmentBooking',
        Sellables::class => 'Commerz\Ports\Sellables\Internal\SellableRouter',
    ];

    public const array PLUGINS = [
        'commerz.core', 'commerz.catalog', 'commerz.inventory', 'commerz.pricing', 'commerz.promotions', 'commerz.shipping', 'commerz.sales',
    ];

    public const array STAGES = ['base', 'promotions.catalog', 'promotions.cart', 'delivery', 'promotions.delivery', 'tax', 'totals'];

    /** @return array<class-string, class-string> port => the class that serves it */
    public static function resolved(): array
    {
        return array_map(static fn (string $port): string => app($port)::class, array_combine(array_keys(self::IMPLEMENTATIONS), array_keys(self::IMPLEMENTATIONS)));
    }

    /** @return list<string> plugin ids, sorted */
    public static function plugins(): array
    {
        $ids = array_map(static fn ($plugin): string => $plugin->id, app(PluginInspector::class)->describe());
        sort($ids);

        return $ids;
    }

    /** @return list<string> the quote stages in the order the pipeline runs them */
    public static function stages(): array
    {
        $contributions = app(Contributions::class);
        $stages = array_map(
            static fn (string $key): QuoteStage => $contributions->get(QuoteStage::KIND, $key, QuoteStage::class),
            $contributions->keys(QuoteStage::KIND),
        );
        usort($stages, static fn (QuoteStage $a, QuoteStage $b): int => [$a->anchor()->order(), $a->position()->order(), $a->key()]
            <=> [$b->anchor()->order(), $b->position()->order(), $b->key()]);

        return array_map(static fn (QuoteStage $stage): string => $stage->key(), $stages);
    }

    /** @return list<string> */
    public static function sellableTypes(): array
    {
        return app(Contributions::class)->keys(SellableProvider::KIND);
    }
}
