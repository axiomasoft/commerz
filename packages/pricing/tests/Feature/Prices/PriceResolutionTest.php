<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Ports\Prices\Exceptions\PriceNotFound;
use Commerz\Pricing\Common\Internal\PricingConfig;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\AssignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListView;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use Commerz\Pricing\Prices\Internal\DatabasePriceResolver;
use Commerz\Pricing\Pricing as PricingRoot;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\StoreId;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../PriceLists/Helpers.php';

function prChannel(string $code = 'web', bool $pricesIncludeTax = false, string $currency = 'RUB'): ChannelId
{
    $store = DB::table('cmz_stores')->where('code', 'main')->value('id')
        ?? app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;

    return app(Channels::class)->create(new CreateChannel(
        new StoreId($store),
        $code,
        ucfirst($code),
        [Currency::of($currency)],
        pricesIncludeTax: $pricesIncludeTax,
    ))->id;
}

/** @param  list<string>  $groups */
function prContext(ChannelId $channel, array $groups = [], string $at = '2026-06-01', string $currency = 'RUB'): CommerceContext
{
    return new CommerceContext($channel, Currency::of($currency), 'en', null, $groups, plsAt($at));
}

function prList(string $code, ChannelId $channel, int $priority = 0, TaxInclusion $inclusion = TaxInclusion::AsChannel, string $currency = 'RUB'): PriceListView
{
    return Pricing::priceLists()->create(new CreatePriceList($code, ucfirst($code), Currency::of($currency), [$channel], $priority, $inclusion));
}

function prRequest(string $sellable = 'tee', int $quantity = 1, ?string $fallback = null): ResolvePrice
{
    return new ResolvePrice(SellableRef::of('variant', $sellable), $quantity, $fallback === null ? null : Money::of($fallback, 'RUB'));
}

function prExplain(string $sellable, int $quantity, CommerceContext $context, ?string $fallback = null): ExplainPrice
{
    return new ExplainPrice(SellableRef::of('variant', $sellable), $quantity, $context, $fallback === null ? null : Money::of($fallback, 'RUB'));
}

/** Makes the next resolution read the config again. */
function prReconfigure(array $config): void
{
    config()->set('commerz-pricing', [...config('commerz-pricing'), ...$config]);

    foreach ([PricingConfig::class, DatabasePriceResolver::class, PriceResolver::class, Prices::class, PricingRoot::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/** The example of knowledge/pricing-design#resolution. */
function prExample(): array
{
    $channel = prChannel();
    $retail = prList('retail', $channel, 0);
    $sale = prList('sale', $channel, 10);
    $contract = prList('contract', prChannel('b2b'), 20); // not assigned to the channel in use

    plsSet($retail->id, amount: '100.00');
    plsSet($retail->id, amount: '90.00', minQuantity: 10);
    plsSet($retail->id, amount: '95.00', group: 'wholesale');
    plsSet($sale->id, amount: '85.00', from: plsAt('2026-05-01'), until: plsAt('2026-07-01'));
    plsSet($contract->id, amount: '1.00');

    return [$channel, $retail, $sale, $contract];
}

it('picks the price of the highest priority list that applies', function (): void {
    [$channel] = prExample();

    [$price] = Pricing::prices()->resolveMany([prRequest(quantity: 10)], prContext($channel, ['wholesale']));

    expect($price->unit)->toEqual(Money::of('85.00', 'RUB'))
        ->and($price->source)->toBe('price_list:sale')
        ->and($price->compareAt)->toBeNull();
});

it('takes the lowest applicable price once the sale is over', function (): void {
    [$channel] = prExample();
    $context = prContext($channel, ['wholesale'], '2026-07-01');

    $price = Pricing::prices()->resolveMany([prRequest(quantity: 10)], $context)[0];
    $why = Pricing::prices()->explain(prExplain('tee', 10, $context));
    $verdicts = array_map(static fn ($candidate): string => "{$candidate->priceList}/{$candidate->customerGroup}/{$candidate->minQuantity}:{$candidate->verdict->value}", $why->candidates);

    expect($price->unit)->toEqual(Money::of('90.00', 'RUB'))
        ->and($price->source)->toBe('price_list:retail')
        ->and($verdicts)->toBe([
            'retail//10:selected',
            'retail/wholesale/1:outranked',
            'retail//1:outranked',
            'sale//1:outside_window',
        ]);
});

it('uses the group price below the quantity step', function (): void {
    [$channel] = prExample();
    $context = prContext($channel, ['wholesale'], '2026-07-01');

    $price = Pricing::prices()->resolveMany([prRequest(quantity: 5)], $context)[0];
    $verdicts = array_map(
        static fn ($candidate): string => "{$candidate->priceList}/{$candidate->customerGroup}/{$candidate->minQuantity}:{$candidate->verdict->value}",
        Pricing::prices()->explain(prExplain('tee', 5, $context))->candidates,
    );

    expect($price->unit)->toEqual(Money::of('95.00', 'RUB'))
        ->and($verdicts)->toBe([
            'retail/wholesale/1:selected',
            'retail//1:outranked',
            'retail//10:below_min_quantity',
            'sale//1:outside_window',
        ]);
});

it('does not offer the group price to a customer outside the group', function (): void {
    [$channel] = prExample();
    $context = prContext($channel, [], '2026-07-01');

    $why = Pricing::prices()->explain(prExplain('tee', 1, $context));

    expect($why->price?->unit)->toEqual(Money::of('100.00', 'RUB'))
        ->and(array_map(static fn ($candidate): CandidateVerdict => $candidate->verdict, $why->candidates))->toBe([
            CandidateVerdict::Selected,
            CandidateVerdict::GroupMismatch,
            CandidateVerdict::BelowMinQuantity,
            CandidateVerdict::OutsideWindow,
        ]);
});

it('lets a list outrank any amount once it is assigned to the channel', function (): void {
    [$channel, , , $contract] = prExample();
    Pricing::priceLists()->assignChannels(new AssignPriceListChannels($contract->id, [$channel]));

    $price = Pricing::prices()->resolveMany([prRequest(quantity: 10)], prContext($channel, ['wholesale']))[0];

    expect($price->unit)->toEqual(Money::of('1.00', 'RUB'))->and($price->source)->toBe('price_list:contract');
});

it('ignores prices of lists that are unassigned, archived or in another currency', function (): void {
    $channel = prChannel();
    $other = prChannel('shop');
    $archived = prList('old', $channel, 50);
    $foreign = Pricing::priceLists()->create(new CreatePriceList('usd', 'Usd', Currency::of('USD'), [], 90));
    $unassigned = Pricing::priceLists()->create(new CreatePriceList('lonely', 'Lonely', Currency::of('RUB'), [], 80));
    $live = prList('live', $channel, 0);
    $elsewhere = prList('elsewhere', $other, 70);

    foreach ([$archived, $unassigned, $elsewhere] as $list) {
        plsSet($list->id, amount: '1.00');
    }

    Pricing::prices()->set(plsPrice($foreign->id, amount: '1.00', currency: 'USD'));
    plsSet($live->id, amount: '50.00');
    Pricing::priceLists()->archive(new ArchivePriceList($archived->id));

    $why = Pricing::prices()->explain(prExplain('tee', 1, prContext($channel)));

    expect($why->price?->unit)->toEqual(Money::of('50.00', 'RUB'))
        ->and($why->price?->source)->toBe('price_list:live')
        ->and($why->candidates)->toHaveCount(1);
});

it('reports a price list amount as including tax by the list or the channel', function (TaxInclusion $inclusion, bool $channelIncludes, bool $expected): void {
    $channel = prChannel('web', $channelIncludes);
    $list = prList('retail', $channel, 0, $inclusion);
    plsSet($list->id, amount: '10.00');

    $price = Pricing::prices()->resolveMany([prRequest()], prContext($channel))[0];

    expect($price->taxIncluded)->toBe($expected);
})->with([
    'included, channel net' => [TaxInclusion::Included, false, true],
    'included, channel gross' => [TaxInclusion::Included, true, true],
    'excluded, channel gross' => [TaxInclusion::Excluded, true, false],
    'excluded, channel net' => [TaxInclusion::Excluded, false, false],
    'as channel, gross' => [TaxInclusion::AsChannel, true, true],
    'as channel, net' => [TaxInclusion::AsChannel, false, false],
]);

it('carries the compare-at amount of the winner', function (): void {
    $channel = prChannel();
    $list = prList('retail', $channel);
    Pricing::prices()->set(plsPrice($list->id, amount: '10.00', compareAt: '15.00'));

    $price = Pricing::prices()->resolveMany([prRequest()], prContext($channel))[0];

    expect($price->compareAt)->toEqual(Money::of('15.00', 'RUB'));
});

it('falls back to the sellable base price in the context currency', function (): void {
    $channel = prChannel('web', true);
    $list = prList('retail', $channel);
    plsSet($list->id, 'tee', '10.00');

    $price = Pricing::prices()->resolveMany([prRequest('mug', 1, '7.00')], prContext($channel))[0];
    $why = Pricing::prices()->explain(prExplain('mug', 1, prContext($channel), '7.00'));

    expect($price->unit)->toEqual(Money::of('7.00', 'RUB'))
        ->and($price->source)->toBe('sellable')
        ->and($price->taxIncluded)->toBeTrue()
        ->and($price->compareAt)->toBeNull()
        ->and($why->fallbackUsed)->toBeTrue()
        ->and($why->price)->toEqual($price)
        ->and($why->candidates)->toBe([]);
});

it('prefers a price list over the fallback', function (): void {
    $channel = prChannel();
    plsSet(prList('retail', $channel)->id, amount: '10.00');

    $price = Pricing::prices()->resolveMany([prRequest('tee', 1, '7.00')], prContext($channel))[0];
    $why = Pricing::prices()->explain(prExplain('tee', 1, prContext($channel), '7.00'));

    expect($price->unit)->toEqual(Money::of('10.00', 'RUB'))->and($why->fallbackUsed)->toBeFalse();
});

it('fails with PriceNotFound when nothing applies and there is no usable fallback', function (?string $fallback, bool $enabled): void {
    prReconfigure(['fallback_to_sellable' => $enabled]);
    $channel = prChannel();
    plsSet(prList('retail', $channel)->id, 'tee', '10.00', group: 'vip');

    try {
        Pricing::prices()->resolveMany([prRequest('tee', 1, $fallback)], prContext($channel));
        $this->fail('A price was resolved.');
    } catch (PriceNotFound $e) {
        expect($e->code())->toBe('prices.price_not_found');
    }
})->with([
    'no fallback given' => [null, true],
    'fallback disabled' => ['7.00', false],
]);

it('does not take a fallback in another currency', function (): void {
    $channel = prChannel();
    $request = new ResolvePrice(SellableRef::of('variant', 'tee'), 1, Money::of('7.00', 'USD'));

    expect(fn () => Pricing::prices()->resolveMany([$request], prContext($channel)))->toThrow(PriceNotFound::class);

    $why = Pricing::prices()->explain(new ExplainPrice($request->sellable, 1, prContext($channel), $request->fallback));

    expect($why->price)->toBeNull()->and($why->fallbackUsed)->toBeFalse();
});

it('explains a missing price without throwing', function (): void {
    $channel = prChannel();
    plsSet(prList('retail', $channel)->id, 'tee', '10.00', minQuantity: 5);

    $why = Pricing::prices()->explain(prExplain('tee', 1, prContext($channel)));

    expect($why->price)->toBeNull()
        ->and($why->fallbackUsed)->toBeFalse()
        ->and($why->candidates)->toHaveCount(1)
        ->and($why->candidates[0]->verdict)->toBe(CandidateVerdict::BelowMinQuantity)
        ->and($why->candidates[0]->amount)->toEqual(Money::of('10.00', 'RUB'))
        ->and($why->candidates[0]->priceList)->toBe('retail');
});

it('answers in the order of the requests, repeated sellables included', function (): void {
    $channel = prChannel();
    $list = prList('retail', $channel);
    plsSet($list->id, 'tee', '10.00');
    plsSet($list->id, 'tee', '8.00', minQuantity: 5);
    plsSet($list->id, 'mug', '3.00');

    $prices = Pricing::prices()->resolveMany(
        [prRequest('mug'), prRequest('tee', 5), prRequest('tee', 1), prRequest('mug', 2), prRequest('tee', 9)],
        prContext($channel),
    );

    expect(array_map(static fn ($price): string => $price->unit->toDecimal(), $prices))->toBe(['3.00', '8.00', '10.00', '3.00', '8.00'])
        ->and(Pricing::prices()->resolveMany([], prContext($channel)))->toBe([]);
});

it('reads the candidates once per call', function (): void {
    $channel = prChannel('web', true);
    $list = prList('retail', $channel, 0, TaxInclusion::AsChannel);
    $requests = [];

    foreach (range(1, 6) as $n) {
        plsSet($list->id, "item{$n}", '10.00');
        $requests[] = prRequest("item{$n}", $n);
        $requests[] = prRequest("fallback{$n}", 1, '5.00');
    }

    $context = prContext($channel);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $prices = Pricing::prices()->resolveMany($requests, $context);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($prices)->toHaveCount(12)
        ->and(count($queries))->toBeLessThanOrEqual(2);
});

it('gives explain the same answer as resolve', function (): void {
    [$channel] = prExample();

    foreach ([['wholesale'], []] as $groups) {
        foreach (['2026-04-01', '2026-06-01', '2026-07-01'] as $at) {
            foreach ([1, 5, 10, 11] as $quantity) {
                $context = prContext($channel, $groups, $at);
                $resolved = Pricing::prices()->resolveMany([prRequest('tee', $quantity)], $context)[0];
                $why = Pricing::prices()->explain(prExplain('tee', $quantity, $context));
                $selected = array_values(array_filter($why->candidates, static fn ($candidate): bool => $candidate->verdict === CandidateVerdict::Selected));

                expect($why->price)->toEqual($resolved)
                    ->and($selected)->toHaveCount(1)
                    ->and($selected[0]->amount)->toEqual($resolved->unit)
                    ->and($resolved->source)->toBe('price_list:'.$selected[0]->priceList);
            }
        }
    }
});

it('is bound as the PriceResolver of the core and prices the base stage of a quote', function (): void {
    $channel = prChannel();
    plsSet(prList('retail', $channel)->id, 'tee', '19.90');

    $sellable = FakeSellables::snapshot(SellableRef::of('variant', 'tee'), Money::of('99.00', 'RUB'));
    $result = app(QuotePipeline::class)->run(new QuoteInput(prContext($channel), [new QuoteLineInput('a', $sellable, 2)]));

    expect(app(PriceResolver::class))->toBeInstanceOf(DatabasePriceResolver::class)
        ->and($result->lines[0]->unitPrice)->toEqual(Money::of('19.90', 'RUB'))
        ->and($result->lines[0]->priceSource)->toBe('price_list:retail')
        ->and($result->total)->toEqual(Money::of('39.80', 'RUB'));
});
