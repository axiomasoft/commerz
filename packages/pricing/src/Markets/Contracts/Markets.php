<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Contracts;

use Commerz\Pagination\Page;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Markets\Data\ArchiveMarket;
use Commerz\Pricing\Markets\Data\CreateMarket;
use Commerz\Pricing\Markets\Data\ListMarkets;
use Commerz\Pricing\Markets\Data\MarketId;
use Commerz\Pricing\Markets\Data\MarketView;
use Commerz\Pricing\Markets\Data\RestoreMarket;
use Commerz\Pricing\Markets\Data\SetMarketCountries;
use Commerz\Pricing\Markets\Data\UpdateMarket;
use Commerz\Pricing\Markets\Exceptions\MarketArchived;
use Commerz\Pricing\Markets\Exceptions\MarketCountryTaken;
use Commerz\Pricing\Markets\Exceptions\MarketNotFound;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Packman\Attributes\Api;

/** Regional markets: countries, currencies, tax inclusion, and default price list linked to a channel. */
#[Api(since: '0.1')]
interface Markets
{
    /**
     * @throws DuplicateCode
     * @throws ChannelNotFound|ChannelArchived|CurrencyNotAllowed
     * @throws PriceListNotFound|PriceListArchived
     * @throws MarketCountryTaken
     */
    public function create(CreateMarket $command): MarketView;

    /**
     * @throws MarketNotFound|MarketArchived
     * @throws ChannelNotFound|ChannelArchived|CurrencyNotAllowed
     * @throws PriceListNotFound|PriceListArchived
     */
    public function update(UpdateMarket $command): MarketView;

    /**
     * @throws MarketNotFound|MarketArchived
     * @throws MarketCountryTaken
     */
    public function setCountries(SetMarketCountries $command): MarketView;

    /** @throws MarketNotFound */
    public function archive(ArchiveMarket $command): MarketView;

    /** @throws MarketNotFound */
    public function restore(RestoreMarket $command): MarketView;

    /** @throws MarketNotFound */
    public function find(MarketId $id): MarketView;

    /** @throws MarketNotFound */
    public function findByCode(string $code): MarketView;

    /** Resolves the active market serving `$country` (ISO-2) in `$channel`, or null when unmapped. */
    public function resolveForCountry(ChannelId $channel, string $country): ?MarketView;

    /** @return Page<MarketView> */
    public function list(ListMarkets $query): Page;
}
