<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\Plugins\PricingPlugin;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListFormula;
use Commerz\Pricing\PriceLists\Data\RoundingRule;
use Commerz\Pricing\PriceLists\Data\UpdatePriceList;
use Commerz\Pricing\PriceLists\Exceptions\PriceListCycleDetected;
use Commerz\Pricing\Prices\Data\DiscardStagedPrices;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\ListStagedPrices;
use Commerz\Pricing\Prices\Data\PublishStagedPrices;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Data\SimulateStagedPrices;
use Commerz\Pricing\Prices\Data\StagePrice;
use Commerz\Pricing\Prices\Data\StagePrices;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use Commerz\Pricing\Prices\Exceptions\StagedPriceStateConflict;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Application;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

require_once __DIR__.'/Support/SqlitePricingSchema.php';

/** Simulates an active 10% catalog promotion + coupon-triggered cart promotion on QuotePipeline. */
final readonly class FspActivePromoStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.active_promotions';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CartPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        foreach ($state->lines as $line) {
            // Automatic 10% off catalog/cart promotion on every line
            $tenPercent = Money::ofMinor(intdiv($line->subtotal()->amountMinor, 10), $state->currency());
            if ($tenPercent->isPositive()) {
                $state = $state->withAdjustment(Adjustment::line(
                    lineId: $line->lineId,
                    amount: $tenPercent->negate(),
                    source: 'promotions.cart',
                    explanation: 'Autumn 10% Off',
                    promotionId: 'promo-autumn-10',
                ));
            }
        }

        if (in_array('EXTRA5', $state->input->codes, true)) {
            $state = $state->withAdjustment(Adjustment::order(
                amount: Money::of('5.00', $state->currency())->negate(),
                source: 'promotions.cart',
                explanation: 'Extra 5 EUR Coupon',
                promotionId: 'promo-extra-5',
                couponCode: 'EXTRA5',
            ));
        }

        return $state;
    }
}

final class FspPromoPlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.fsp-promos', '1.0.0', [CorePlugin::ID, PricingPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, FspActivePromoStage::class);
    }
}

trait FspEnvironment
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [FspPromoPlugin::class]);
    }
}

uses(FspEnvironment::class);

beforeEach(function (): void {
    bootSqlitePricingSchema('formulas_sqlite');
});

it('derives prices across multi-level formula price lists, applies rounding rules, respects overrides, and rejects cycles', function (): void {
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel(
        $store->id,
        'intl',
        'International',
        [Currency::of('EUR'), Currency::of('USD'), Currency::of('JPY')],
        defaultCurrency: Currency::of('EUR'),
    ));

    // Base wholesale EUR list (not assigned to storefront channel directly)
    $costEur = Pricing::priceLists()->create(new CreatePriceList('cost-eur', 'Cost EUR', Currency::of('EUR')));
    Pricing::prices()->set(new SetPrice($costEur->id, SellableRef::of('variant', 'jacket'), Money::of('100.00', 'EUR'), compareAt: Money::of('140.00', 'EUR')));
    Pricing::prices()->set(new SetPrice($costEur->id, SellableRef::of('variant', 'cap'), Money::of('20.00', 'EUR')));

    // Child 1: Retail EUR = Cost + 20% margin + .99 psychological rounding
    $retailEur = Pricing::priceLists()->create(new CreatePriceList(
        code: 'retail-eur',
        name: 'Retail EUR',
        currency: Currency::of('EUR'),
        channels: [$channel->id],
        priority: 0,
        parentPriceListId: $costEur->id,
        formula: PriceListFormula::marginPercent(2000),
        roundingRule: RoundingRule::ending99(),
    ));

    // Child 2 (grandchild): USD Storefront = Retail EUR * 1.10 FX + 150bp spread + .90 psychological rounding
    $retailUsd = Pricing::priceLists()->create(new CreatePriceList(
        code: 'retail-usd',
        name: 'Retail USD',
        currency: Currency::of('USD'),
        channels: [$channel->id],
        priority: 0,
        parentPriceListId: $retailEur->id,
        formula: PriceListFormula::fxRateWithSpread('1.10', spreadBp: 150),
        roundingRule: RoundingRule::ending90(),
    ));

    // Child 3: JPY Storefront = Cost EUR * 160.25 FX + multiple_of_10 rounding
    Pricing::priceLists()->create(new CreatePriceList(
        code: 'retail-jpy',
        name: 'Retail JPY',
        currency: Currency::of('JPY'),
        channels: [$channel->id],
        priority: 0,
        parentPriceListId: $costEur->id,
        formula: PriceListFormula::fxRateWithSpread('160.25'),
        roundingRule: RoundingRule::multipleOf10(),
    ));

    $at = new DateTimeImmutable('2026-11-26T12:00:00Z');
    $eurCtx = new CommerceContext($channel->id, Currency::of('EUR'), 'en', null, [], $at);
    $usdCtx = new CommerceContext($channel->id, Currency::of('USD'), 'en', null, [], $at);
    $jpyCtx = new CommerceContext($channel->id, Currency::of('JPY'), 'ja', null, [], $at);

    // EUR: 100.00 + 20% = 120.00 -> ending_99 = 119.99 EUR; compareAt: 140.00 + 20% = 168.00 -> 167.99 EUR
    [$jacketEur, $capEur] = Pricing::prices()->resolveMany([
        new ResolvePrice(SellableRef::of('variant', 'jacket'), 1),
        new ResolvePrice(SellableRef::of('variant', 'cap'), 1),
    ], $eurCtx);
    expect($jacketEur->unit->toDecimal())->toBe('119.99')
        ->and($jacketEur->compareAt?->toDecimal())->toBe('167.99')
        ->and($jacketEur->source)->toBe('price_list:retail-eur')
        ->and($capEur->unit->toDecimal())->toBe('23.99');

    // USD from grandchild: 119.99 * 1.10 * 1.015 = 133.968835 -> 133.97 -> ending_90 = 133.90 USD
    [$jacketUsd] = Pricing::prices()->resolveMany([new ResolvePrice(SellableRef::of('variant', 'jacket'), 1)], $usdCtx);
    expect($jacketUsd->unit->toDecimal())->toBe('133.90')
        ->and($jacketUsd->source)->toBe('price_list:retail-usd');

    // JPY: 20.00 * 160.25 = 3205 JPY -> multiple_of_10 = 3210 JPY
    [$capJpy] = Pricing::prices()->resolveMany([new ResolvePrice(SellableRef::of('variant', 'cap'), 1)], $jpyCtx);
    expect($capJpy->unit->toDecimal())->toBe('3210');

    // Explicit override on child list beats derived parent price (and applies child list rounding rule if set)
    Pricing::prices()->set(new SetPrice($retailEur->id, SellableRef::of('variant', 'cap'), Money::of('19.00', 'EUR')));
    [$overriddenCap] = Pricing::prices()->resolveMany([new ResolvePrice(SellableRef::of('variant', 'cap'), 1)], $eurCtx);
    expect($overriddenCap->unit->toDecimal())->toBe('18.99');

    // Cycle detection: costEur -> retailUsd -> retailEur -> costEur rejected
    expect(fn () => Pricing::priceLists()->update(new UpdatePriceList(
        priceList: $costEur->id,
        parentPriceListId: $retailEur->id,
        formula: PriceListFormula::fixedDelta(100),
    )))->toThrow(PriceListCycleDetected::class);

    // Archiving ancestor stops un-overridden derived prices
    Pricing::priceLists()->archive(new ArchivePriceList($costEur->id));
    expect(Pricing::prices()->explain(new ExplainPrice(SellableRef::of('variant', 'jacket'), 1, $eurCtx))->price)
        ->toBeNull();
});

it('stages prices in cmz_staged_prices, simulates them against active promotions without mutating live prices, and publishes atomically', function (): void {
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('EUR')]));
    $retail = Pricing::priceLists()->create(new CreatePriceList('retail', 'Retail', Currency::of('EUR'), [$channel->id]));

    $bootsRef = SellableRef::of('variant', 'boots');
    $scarfRef = SellableRef::of('variant', 'scarf');

    // Live prices: boots = 100.00 EUR, scarf = 50.00 EUR
    Pricing::prices()->set(new SetPrice($retail->id, $bootsRef, Money::of('100.00', 'EUR')));
    Pricing::prices()->set(new SetPrice($retail->id, $scarfRef, Money::of('50.00', 'EUR')));

    // Stage Black Friday prices: boots = 80.00 EUR (compareAt 100.00), scarf = 40.00 EUR
    $staged = Pricing::stagedPrices()->stageMany(new StagePrices(
        priceList: $retail->id,
        prices: [
            new StagePrice($retail->id, $bootsRef, Money::of('80.00', 'EUR'), compareAt: Money::of('100.00', 'EUR')),
            new StagePrice($retail->id, $scarfRef, Money::of('40.00', 'EUR')),
        ],
        batchCode: 'bf-2026',
    ));

    expect($staged)->toHaveCount(2)
        ->and($staged[0]->status)->toBe(StagedPriceStatus::Staged);

    $ctx = new CommerceContext($channel->id, Currency::of('EUR'), 'en', null, [], new DateTimeImmutable('2026-11-26T12:00:00Z'));

    // Simulate against active promotions (10% automatic promo + EXTRA5 coupon = 5.00 order discount)
    $simulation = Pricing::stagedPrices()->simulate(SimulateStagedPrices::forSellables(
        context: $ctx,
        sellables: [
            FakeSellables::snapshot($bootsRef, Money::of('100.00', 'EUR')),
            FakeSellables::snapshot($scarfRef, Money::of('50.00', 'EUR')),
        ],
        batchCode: 'bf-2026',
        codes: ['EXTRA5'],
    ));

    // Staged subtotal = 80 + 40 = 120.00 EUR
    // Automatic 10% promo = 8.00 + 4.00 = 12.00 EUR; EXTRA5 order discount = 5.00 EUR -> total promo discount = 17.00 EUR -> 103.00 EUR!
    expect($simulation->hasStackedPromotions)->toBeTrue()
        ->and($simulation->totalPromotionDiscount->toDecimal())->toBe('17.00')
        ->and($simulation->simulatedQuote->totals->subtotal->toDecimal())->toBe('120.00')
        ->and($simulation->simulatedQuote->totals->total->toDecimal())->toBe('103.00')
        ->and($simulation->liveQuote?->totals->subtotal->toDecimal())->toBe('150.00')
        ->and($simulation->lines[0]->liveUnitPrice?->toDecimal())->toBe('100.00')
        ->and($simulation->lines[0]->stagedUnitPrice->toDecimal())->toBe('80.00')
        ->and($simulation->lines[0]->stacksWithPromotions)->toBeTrue()
        ->and(count($simulation->appliedPromotions))->toBe(2);

    // Live prices are STILL untouched before publish!
    [$liveBoots] = Pricing::prices()->resolveMany([new ResolvePrice($bootsRef, 1)], $ctx);
    expect($liveBoots->unit->toDecimal())->toBe('100.00');

    // Now publish batch 'bf-2026'
    $published = Pricing::stagedPrices()->publish(new PublishStagedPrices(batchCode: 'bf-2026'));
    expect($published)->toHaveCount(2);

    [$afterPublishBoots] = Pricing::prices()->resolveMany([new ResolvePrice($bootsRef, 1)], $ctx);
    expect($afterPublishBoots->unit->toDecimal())->toBe('80.00')
        ->and($afterPublishBoots->compareAt?->toDecimal())->toBe('100.00')
        ->and($afterPublishBoots->lowestPriceLast30Days?->toDecimal())->toBe('100.00'); // EU Omnibus populated automatically!

    $publishedRow = Pricing::stagedPrices()->find($staged[0]->id);
    expect($publishedRow->status)->toBe(StagedPriceStatus::Published)
        ->and($publishedRow->publishedPriceId)->not->toBeNull()
        ->and(fn () => Pricing::stagedPrices()->discard(new DiscardStagedPrices(stagedPriceIds: [$staged[0]->id])))
        ->toThrow(StagedPriceStateConflict::class);

    // Stage and discard another draft
    $draft = Pricing::stagedPrices()->stage(new StagePrice($retail->id, $scarfRef, Money::of('35.00', 'EUR'), batchCode: 'cyber-2026'));
    $discarded = Pricing::stagedPrices()->discard(new DiscardStagedPrices(batchCode: 'cyber-2026'));
    expect($discarded[0]->status)->toBe(StagedPriceStatus::Discarded)
        ->and(Pricing::stagedPrices()->list(new ListStagedPrices(status: StagedPriceStatus::Published))->items)->toHaveCount(2);
});
