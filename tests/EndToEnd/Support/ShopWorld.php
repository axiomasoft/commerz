<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Support;

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Facades\Commerz;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\CreateStockItem;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Shipping\Facades\Shipping;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\ZoneRate;
use Commerz\Shipping\Rates\Data\RateSpec;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;

/**
 * The shop of the end-to-end scenarios, built through the public API of every package.
 *
 * Channel `web` (RUB, `ru`, prices include tax); a T-shirt in sizes S and M and a mug; stock on `msk` (S 5, M 2, mug 10);
 * one `retail` price list (S and M 1000.00, mug 500.00) and VAT 20% in Russia; an automatic −10% on the order and the
 * coupon `SALE100` (−100.00 on the order, 1 per customer, 5 in all); a Russian courier of the `manual` carrier at
 * 300.00, free above 5000.00. Scenarios create any further data themselves.
 */
final readonly class ShopWorld
{
    public const string COUPON = 'SALE100';

    public const string COURIER = 'courier';

    public const string LOCATION = 'msk';

    public function __construct(
        public ChannelId $channel,
        public SellableRef $small,
        public SellableRef $medium,
        public SellableRef $mug,
        public StockItemId $smallItem,
        public StockItemId $mediumItem,
        public StockItemId $mugItem,
        public LocationId $location,
        public PromotionId $automaticPromotion,
        public PromotionId $couponPromotion,
    ) {}

    public static function build(): self
    {
        $ru = static fn (string $text): LocalizedText => LocalizedText::of('ru', $text);
        $rub = Currency::of('RUB');

        $store = Commerz::stores()->create(new CreateStore('shop', 'Shop', $rub));
        $channel = Commerz::channels()->create(new CreateChannel($store->id, 'web', 'Web', [$rub], ['ru'], pricesIncludeTax: true))->id;

        Catalog::options()->define(new DefineOption('size', $ru('Размер'), ['s' => $ru('S'), 'm' => $ru('M')]));
        $apparel = Catalog::productTypes()->create(new CreateProductType('apparel', $ru('Одежда'), variantAxes: ['size']));
        $accessory = Catalog::productTypes()->create(new CreateProductType('accessory', $ru('Аксессуар')));
        $tee = Catalog::products()->create(new CreateProduct($apparel->id, 'tee', $ru('Футболка')));
        $mugProduct = Catalog::products()->create(new CreateProduct($accessory->id, 'mug', $ru('Кружка')));
        $small = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-S', ['size' => 's'], weightGrams: 300));
        $medium = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-M', ['size' => 'm'], weightGrams: 300));
        $mug = Catalog::variants()->create(new CreateVariant($mugProduct->id, 'MUG', weightGrams: 400));
        Catalog::listings()->publish(new PublishProduct($tee->id, $channel));
        Catalog::listings()->publish(new PublishProduct($mugProduct->id, $channel));

        $location = Inventory::locations()->create(new CreateLocation(self::LOCATION, 'Moscow'))->id;
        Inventory::sources()->assign(new AssignSource($channel, $location, 100));
        $items = [];

        foreach ([[$small->sku, $small->sellable, 5], [$medium->sku, $medium->sellable, 2], [$mug->sku, $mug->sellable, 10]] as [$sku, $sellable, $quantity]) {
            $item = Inventory::items()->create(new CreateStockItem($sku))->id;
            Inventory::items()->link(new LinkSellable($sellable, $item, 1));
            Inventory::stock()->receive(new ReceiveStock($item, $location, $quantity));
            $items[] = $item;
        }

        $retail = Pricing::priceLists()->create(new CreatePriceList('retail', 'Retail', $rub, [$channel]));

        foreach ([[$small->sellable, '1000.00'], [$medium->sellable, '1000.00'], [$mug->sellable, '500.00']] as [$sellable, $amount]) {
            Pricing::prices()->set(new SetPrice($retail->id, $sellable, Money::of($amount, 'RUB')));
        }

        Pricing::taxes()->defineClass(new DefineTaxClass('standard', 'Standard'));
        Pricing::taxes()->defineZone(new DefineTaxZone('ru', 'Russia', [new TaxZoneMember('RU')]));
        Pricing::taxes()->setRate(new SetTaxRate('standard', 'ru', 2000, 'vat'));

        $automatic = Promotions::promotions()->create(new CreatePromotion(
            'autumn-10', 'Autumn -10%', PromotionStage::Cart, 'automatic', [$channel], [new RuleSpec('order.percent_off', ['rateBp' => 1000])],
        ));
        $couponPromotion = Promotions::promotions()->create(new CreatePromotion(
            'sale-100', 'Sale -100', PromotionStage::Cart, 'coupon', [$channel], [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
            limits: new UsageLimits(total: 5, perCustomer: 1),
        ));
        Promotions::coupons()->create(new CreateCoupon($couponPromotion->id, self::COUPON));

        $zone = Shipping::zones()->create(new CreateZone('ru', 'Russia', [ZoneMember::country('RU')]));
        Shipping::methods()->create(new CreateMethod(self::COURIER, LocalizedText::of('ru', 'Курьер'), 'manual', [$channel], [
            new ZoneRate($zone->id, $rub, new RateSpec('flat', ['amount' => Money::of('300.00', 'RUB')]), Money::of('5000.00', 'RUB'), taxIncluded: true),
        ]));

        return new self(
            $channel, $small->sellable, $medium->sellable, $mug->sellable, $items[0], $items[1], $items[2],
            $location, $automatic->id, $couponPromotion->id,
        );
    }
}
