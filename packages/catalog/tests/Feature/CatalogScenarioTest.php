<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests\Feature;

use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Tests\TestCase;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Context\CommerceContext;
use Commerz\Facades\Commerz;
use Commerz\Identity\SellableRef;
use Commerz\Measures\LocalizedText;
use Commerz\Measures\Measure;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakePrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/** The blueprint's catalog example, end to end: modelled, generated, published, searched, sold into a quote. */
final class CatalogScenarioTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_takes_a_modelled_product_from_the_catalog_into_a_quote(): void
    {
        $ru = static fn (string $text): LocalizedText => LocalizedText::of('ru', $text);

        $store = Commerz::stores()->create(new CreateStore('shop', 'Shop', Currency::of('RUB')));
        $channel = Commerz::channels()->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')], ['ru']));

        Catalog::options()->define(new DefineOption('color', $ru('Цвет'), ['red' => $ru('Красный'), 'blue' => $ru('Синий')]));
        Catalog::options()->define(new DefineOption('size', $ru('Размер'), ['s' => $ru('S'), 'm' => $ru('M')]));
        Catalog::attributes()->define(new DefineAttribute('material', 'enum', $ru('Материал'), choices: ['cotton' => $ru('Хлопок'), 'linen' => $ru('Лён')], filterable: true));
        Catalog::attributes()->define(new DefineAttribute('density', 'measure', $ru('Плотность'), config: ['unit' => 'gsm'], filterable: true));
        $type = Catalog::productTypes()->create(new CreateProductType(
            'apparel', $ru('Одежда'), productAttributes: ['material', 'density'], variantAxes: ['color', 'size'],
        ));
        $product = Catalog::products()->create(new CreateProduct(
            $type->id, 'basic-tee', $ru('Футболка'), attributes: ['material' => 'cotton', 'density' => Measure::of('180', 'gsm')],
        ));
        $generated = Catalog::variants()->generate(new GenerateVariants(
            $product->id, ['color' => ['red', 'blue'], 'size' => ['s', 'm']], 'TEE-{color}-{size}', except: [['color' => 'blue', 'size' => 's']],
        ));
        Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

        $this->assertCount(3, $generated->created);

        /** @var array<string, VariantView> $bySku */
        $bySku = [];
        foreach ($generated->created as $variant) {
            $bySku[$variant->sku] = $variant;
        }

        $prices = new FakePrices;
        foreach (['TEE-red-s' => '1000.00', 'TEE-red-m' => '1200.00', 'TEE-blue-m' => '1500.00'] as $sku => $amount) {
            $prices->set($bySku[$sku]->sellable, Money::of($amount, 'RUB'));
        }
        $this->app->instance(PriceResolver::class, $prices);

        $context = CommerceContext::for($channel->id, Currency::of('RUB'), 'ru');
        $refs = array_map(static fn (VariantView $variant): SellableRef => $variant->sellable, array_values($bySku));
        $sellables = Commerz::sellables()->resolveMany($refs, $context);

        foreach ($sellables as $snapshot) {
            $this->assertTrue($snapshot->purchasable);
            $this->assertSame('Футболка', $snapshot->name);
            $this->assertSame(['material' => 'cotton'], $snapshot->attributes);
        }

        $quote = Commerz::quotes()->run(new QuoteInput($context, [
            new QuoteLineInput('1', $sellables[(string) $bySku['TEE-red-s']->sellable], 1),
            new QuoteLineInput('2', $sellables[(string) $bySku['TEE-red-m']->sellable], 2),
            new QuoteLineInput('3', $sellables[(string) $bySku['TEE-blue-m']->sellable], 1),
        ]));

        $this->assertEquals(Money::of('4900.00', 'RUB'), $quote->total);
        $this->assertSame(['Красный', 'S'], array_values($sellables[(string) $bySku['TEE-red-s']->sellable]->options));

        $found = Catalog::products()->list(new ListProducts(
            channel: $channel->id,
            filters: [AttributeFilter::equals('material', 'cotton'), VariantFilter::axes(['color' => 'red', 'size' => 'm'])],
        ));
        $missing = Catalog::products()->list(new ListProducts(
            channel: $channel->id,
            filters: [VariantFilter::axes(['color' => 'blue', 'size' => 's'])],
        ));

        $this->assertSame(['basic-tee'], array_map(static fn ($item): string => $item->handle, $found->items));
        $this->assertSame([], $missing->items);
    }
}
