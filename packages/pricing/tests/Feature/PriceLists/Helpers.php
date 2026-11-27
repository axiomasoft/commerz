<?php

declare(strict_types=1);

// Shared fixtures of the price list and price tests.

use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Data\PriceListView;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\StoreId;
use Illuminate\Support\Facades\DB;

function plsMainStoreId(): string
{
    $existing = DB::table('cmz_stores')->where('code', 'main')->value('id');
    $existing ??= app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;

    if (! is_string($existing)) {
        throw new RuntimeException('Store id must be a string.');
    }

    return $existing;
}

function plsChannel(string $code = 'web', string $currency = 'RUB'): ChannelId
{
    return app(Channels::class)->create(new CreateChannel(new StoreId(plsMainStoreId()), $code, ucfirst($code), [Currency::of($currency)]))->id;
}

/** @param  list<ChannelId>  $channels */
function plsList(string $code = 'retail', array $channels = [], string $currency = 'RUB', int $priority = 0): PriceListView
{
    return Pricing::priceLists()->create(new CreatePriceList($code, ucfirst($code), Currency::of($currency), $channels, $priority));
}

function plsPrice(
    PriceListId $list,
    string $sellable = 'tee',
    string $amount = '10.00',
    ?string $group = null,
    int $minQuantity = 1,
    ?DateTimeImmutable $from = null,
    ?DateTimeImmutable $until = null,
    ?string $compareAt = null,
    string $currency = 'RUB',
): SetPrice {
    return new SetPrice(
        $list,
        SellableRef::of('variant', $sellable),
        Money::of($amount, $currency),
        $compareAt === null ? null : Money::of($compareAt, $currency),
        $minQuantity,
        $group,
        $from,
        $until,
    );
}

function plsSet(PriceListId $list, string $sellable = 'tee', string $amount = '10.00', ?string $group = null, int $minQuantity = 1, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): PriceView
{
    return Pricing::prices()->set(plsPrice($list, $sellable, $amount, $group, $minQuantity, $from, $until));
}

function plsAt(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
}
