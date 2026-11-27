<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListView;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\ListPrices;
use Commerz\Pricing\Prices\Data\PriceCandidate;
use Commerz\Pricing\Prices\Data\ReschedulePrice;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use Commerz\Pricing\Taxes\Data\RescheduleTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeSellables;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/PriceLists/Helpers.php';
require_once __DIR__.'/Taxes/Helpers.php';

function pscChannel(): ChannelId
{
    $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')));

    return app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')], pricesIncludeTax: true))->id;
}

function pscList(string $code, ChannelId $channel, int $priority = 0): PriceListView
{
    return Pricing::priceLists()->create(new CreatePriceList($code, ucfirst($code), Currency::of('RUB'), [$channel], $priority));
}

function pscContext(ChannelId $channel, string $at): CommerceContext
{
    return new CommerceContext($channel, Currency::of('RUB'), 'ru', null, [], plsAt($at));
}

/**
 * The line is quoted through the real pipeline: pricing is the PriceResolver and owns the `tax` stage.
 *
 * @return array{string, string, list<string>}
 */
function pscQuote(ChannelId $channel, string $at): array
{
    $sellable = FakeSellables::snapshot(SellableRef::of('variant', 'tee'), Money::of('999.00', 'RUB'));
    $result = app(QuotePipeline::class)->run(new QuoteInput(pscContext($channel, $at), [new QuoteLineInput('a', $sellable, 1)]));

    return [
        $result->lines[0]->priceSource,
        $result->total->toDecimal(),
        array_map(static fn (TaxLine $tax): string => $tax->code.'|'.$tax->rate->basisPoints.'|'.$tax->amount->toDecimal().'|'.($tax->included ? 'in' : 'ex'), $result->taxLines),
    ];
}

it('prices and taxes a sale across a VAT change from lists to a quote', function (): void {
    config(['commerz-pricing.tax.default_zone' => 'ru']);
    app()->forgetInstance(PricingOptions::class);

    $channel = pscChannel();
    $retail = pscList('retail', $channel);
    $sale = pscList('sale', $channel, priority: 10);

    $tee = SellableRef::of('variant', 'tee');
    $batch = Pricing::prices()->setMany(new SetPrices($sale->id, [plsPrice($sale->id, 'tee', '122.00', from: plsAt('2025-12-01'), until: plsAt('2026-02-01'))]));
    $retailPrice = plsSet($retail->id, 'tee', '150.00');

    expect($batch)->toHaveCount(1)
        ->and(Pricing::prices()->list(new ListPrices($retail->id))->items)->toHaveCount(1)
        ->and(Pricing::prices()->list(new ListPrices($sale->id, $tee))->items)->toHaveCount(1);

    // standard / ru, VAT 20% until the end of 2025, 22% from 2026-01-01 (425-FZ), moved atomically.
    txClass();
    txZone();
    $old = txRate(bp: 2000);
    app(Transactions::class)->run(function () use ($old): void {
        Pricing::taxes()->rescheduleRate(new RescheduleTaxRate($old->id, null, txAt('2026-01-01')));
        Pricing::taxes()->setRate(new SetTaxRate('standard', 'ru', 2200, 'vat', txAt('2026-01-01')));
    });

    // 20/120 of 122.00 = 20.33; 22/122 of 122.00 = 22.00 — gross stays, the carved-out tax changes.
    expect(pscQuote($channel, '2025-12-31'))->toBe(['price_list:sale', '122.00', ['vat|2000|20.33|in']])
        ->and(pscQuote($channel, '2026-01-02'))->toBe(['price_list:sale', '122.00', ['vat|2200|22.00|in']]);

    $why = Pricing::prices()->explain(new ExplainPrice($tee, 1, pscContext($channel, '2026-01-02')));
    $verdicts = array_column(array_map(static fn (PriceCandidate $c): array => ['list' => $c->priceList, 'verdict' => $c->verdict], $why->candidates), 'verdict', 'list');

    expect($verdicts)->toBe(['sale' => CandidateVerdict::Selected, 'retail' => CandidateVerdict::Outranked])
        ->and($why->price?->source)->toBe('price_list:sale');

    // The sale window is over: retail wins and the 22% rate applies to its gross.
    expect(pscQuote($channel, '2026-03-01'))->toBe(['price_list:retail', '150.00', ['vat|2200|27.05|in']]);

    // Ending the retail price sends the quote back to the sellable's own base price (999.00 gross).
    Pricing::prices()->reschedule(new ReschedulePrice($retailPrice->id, null, plsAt('2026-03-01')));
    expect(pscQuote($channel, '2026-03-02')[1])->toBe('999.00');
});
