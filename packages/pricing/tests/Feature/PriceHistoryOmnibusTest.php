<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Prices\Data\ResolvePrice;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\Prices\Data\ExplainPrice;
use Commerz\Pricing\Prices\Data\ListPriceHistory;
use Commerz\Pricing\Prices\Data\RemovePrice;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Enums\PriceHistoryEvent;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/SqlitePricingSchema.php';

beforeEach(function (): void {
    $this->clock = bootSqlitePricingSchema('omnibus_sqlite', '2026-10-01T10:00:00Z');
});

it('tracks a 45-day price history and computes the rolling 30-day EU Omnibus minimum when compareAt is present (INV-PRC-002)', function (): void {
    $store = app(Stores::class)->create(new CreateStore('eu', 'EU Store', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'web-eu', 'EU Web', [Currency::of('EUR')], pricesIncludeTax: true));
    $list = Pricing::priceLists()->create(new CreatePriceList('retail-eu', 'Retail EU', Currency::of('EUR'), [$channel->id]));
    $sku = SellableRef::of('variant', 'headphones');

    // Day 1 (2026-10-01, T - 44 days): Deep clearance price 50.00 EUR
    $this->clock->set('2026-10-01T10:00:00Z');
    Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('50.00', 'EUR')));

    // Day 10 (2026-10-10, T - 35 days): Raised to 100.00 EUR (remains in force across Day 15 = T - 30d up to Day 20!)
    $this->clock->set('2026-10-10T10:00:00Z');
    Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('100.00', 'EUR')));

    // Day 20 (2026-10-20, T - 25 days): Mid-month dip to 80.00 EUR
    $this->clock->set('2026-10-20T10:00:00Z');
    Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('80.00', 'EUR')));

    // Day 30 (2026-10-30, T - 15 days): Regular price raised to 120.00 EUR (no compareAt -> lowestPriceLast30Days must be null)
    $this->clock->set('2026-10-30T10:00:00Z');
    Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('120.00', 'EUR')));

    $ctxDay30 = new CommerceContext($channel->id, Currency::of('EUR'), 'de', null, [], new DateTimeImmutable('2026-10-30T12:00:00Z'));
    [$regularResolved] = Pricing::prices()->resolveMany([new ResolvePrice($sku, 1)], $ctxDay30);
    expect($regularResolved->compareAt)->toBeNull()
        ->and($regularResolved->lowestPriceLast30Days)->toBeNull();

    // Day 45 (2026-11-14, 44 days after Oct 1; 30-day window is [2026-10-15T10:00:00Z, 2026-11-14T10:00:00Z]):
    // Announce sale at 95.00 EUR with compareAt = 120.00 EUR!
    // - 50.00 EUR (Oct 1..Oct 10) ended 35 days ago -> OUTSIDE 30-day window!
    // - 100.00 EUR (Oct 10..Oct 20) was active on Oct 15..20 -> INSIDE 30-day window!
    // - 80.00 EUR (Oct 20..Oct 30) -> INSIDE 30-day window!
    // - 120.00 EUR (Oct 30..Nov 14) -> INSIDE 30-day window!
    // Lowest prior price in last 30 days MUST be 80.00 EUR!
    $this->clock->set('2026-11-14T10:00:00Z');
    Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('95.00', 'EUR'), compareAt: Money::of('120.00', 'EUR')));

    $ctxDay45 = new CommerceContext($channel->id, Currency::of('EUR'), 'de', null, [], new DateTimeImmutable('2026-11-14T10:00:00Z'));
    [$saleDay45] = Pricing::prices()->resolveMany([new ResolvePrice($sku, 1)], $ctxDay45);
    $explainedDay45 = Pricing::prices()->explain(new ExplainPrice($sku, 1, $ctxDay45));

    expect($saleDay45->unit->toDecimal())->toBe('95.00')
        ->and($saleDay45->compareAt?->toDecimal())->toBe('120.00')
        ->and($saleDay45->lowestPriceLast30Days?->toDecimal())->toBe('80.00')
        ->and($explainedDay45->price)->toEqual($saleDay45);

    // Day 52 (2026-11-21, window starts 2026-10-22): 80.00 EUR was active until Oct 30 >= Oct 22 -> STILL 80.00 EUR!
    $ctxDay52 = new CommerceContext($channel->id, Currency::of('EUR'), 'de', null, [], new DateTimeImmutable('2026-11-21T10:00:00Z'));
    [$saleDay52] = Pricing::prices()->resolveMany([new ResolvePrice($sku, 1)], $ctxDay52);
    expect($saleDay52->lowestPriceLast30Days?->toDecimal())->toBe('80.00');

    // Day 61 (2026-11-30, window starts 2026-10-31): 80.00 EUR ended Oct 30 < Oct 31 -> ages out! Prior minimum becomes 120.00 EUR!
    $ctxDay61 = new CommerceContext($channel->id, Currency::of('EUR'), 'de', null, [], new DateTimeImmutable('2026-11-30T10:00:00Z'));
    [$saleDay61] = Pricing::prices()->resolveMany([new ResolvePrice($sku, 1)], $ctxDay61);
    expect($saleDay61->lowestPriceLast30Days?->toDecimal())->toBe('120.00');

    // Day 62 (2026-12-01): Progressive deeper markdown to 85.00 EUR (compareAt 120.00 EUR) -> 95.00 EUR from Nov 14..Dec 1 is now the lowest prior price!
    $this->clock->set('2026-12-01T10:00:00Z');
    $latestView = Pricing::prices()->set(new SetPrice($list->id, $sku, Money::of('85.00', 'EUR'), compareAt: Money::of('120.00', 'EUR')));
    $ctxDay62 = new CommerceContext($channel->id, Currency::of('EUR'), 'de', null, [], new DateTimeImmutable('2026-12-01T10:00:00Z'));
    [$saleDay62] = Pricing::prices()->resolveMany([new ResolvePrice($sku, 1)], $ctxDay62);
    expect($saleDay62->lowestPriceLast30Days?->toDecimal())->toBe('95.00');

    // Removing the live price appends a 'removed' event and preserves the entire append-only journal (7 entries total)!
    $this->clock->set('2026-12-02T10:00:00Z');
    Pricing::prices()->remove(new RemovePrice($latestView->id));

    $journal = Pricing::prices()->history(new ListPriceHistory($sku));
    expect($journal)->toHaveCount(7)
        ->and($journal[0]->event)->toBe(PriceHistoryEvent::Removed)
        ->and($journal[1]->event)->toBe(PriceHistoryEvent::Set)
        ->and($journal[1]->amount->toDecimal())->toBe('85.00');
});

it('resolves lowestPriceLast30Days for multiple discounted SKUs in a single batched history query', function (): void {
    $store = app(Stores::class)->create(new CreateStore('eu', 'EU Store', Currency::of('EUR')));
    $channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('EUR')], pricesIncludeTax: true));
    $list = Pricing::priceLists()->create(new CreatePriceList('retail', 'Retail', Currency::of('EUR'), [$channel->id]));

    $requests = [];
    $this->clock->set('2026-11-10T10:00:00Z');
    for ($i = 1; $i <= 10; $i++) {
        $ref = SellableRef::of('variant', "item-{$i}");
        Pricing::prices()->set(new SetPrice($list->id, $ref, Money::of((string) (100 + $i).'.00', 'EUR')));
    }

    $this->clock->set('2026-11-26T10:00:00Z');
    for ($i = 1; $i <= 10; $i++) {
        $ref = SellableRef::of('variant', "item-{$i}");
        Pricing::prices()->set(new SetPrice(
            $list->id,
            $ref,
            Money::of((string) (80 + $i).'.00', 'EUR'),
            compareAt: Money::of((string) (120 + $i).'.00', 'EUR'),
        ));
        $requests[] = new ResolvePrice($ref, 1);
    }

    $ctx = new CommerceContext($channel->id, Currency::of('EUR'), 'en', null, [], new DateTimeImmutable('2026-11-26T12:00:00Z'));
    DB::connection('omnibus_sqlite')->flushQueryLog();
    DB::connection('omnibus_sqlite')->enableQueryLog();
    $resolved = Pricing::prices()->resolveMany($requests, $ctx);
    $queries = DB::connection('omnibus_sqlite')->getQueryLog();
    DB::connection('omnibus_sqlite')->disableQueryLog();

    expect($resolved)->toHaveCount(10)
        ->and($resolved[0]->lowestPriceLast30Days?->toDecimal())->toBe('101.00')
        ->and($resolved[9]->lowestPriceLast30Days?->toDecimal())->toBe('110.00')
        ->and(count($queries))->toBeLessThanOrEqual(4); // channel lists + prices + channel tax + 1 batched history query
});
