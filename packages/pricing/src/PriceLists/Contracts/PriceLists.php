<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Contracts;

use Commerz\Attributes\Api;
use Commerz\Pagination\Page;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\PriceLists\Data\ArchivePriceList;
use Commerz\Pricing\PriceLists\Data\AssignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\ListPriceLists;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\PriceLists\Data\PriceListView;
use Commerz\Pricing\PriceLists\Data\RestorePriceList;
use Commerz\Pricing\PriceLists\Data\UnassignPriceListChannels;
use Commerz\Pricing\PriceLists\Data\UpdatePriceList;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;

/** Price lists: a currency, a priority, a tax inclusion and the channels they serve. */
#[Api(since: '0.1')]
interface PriceLists
{
    /**
     * @throws DuplicateCode
     * @throws ChannelNotFound|ChannelArchived
     * @throws CurrencyNotAllowed a channel does not sell in the currency of the list
     */
    public function create(CreatePriceList $command): PriceListView;

    /** @throws PriceListNotFound|PriceListArchived */
    public function update(UpdatePriceList $command): PriceListView;

    /**
     * Adds channels; one already assigned stays.
     *
     * @throws PriceListNotFound|PriceListArchived
     * @throws ChannelNotFound|ChannelArchived
     * @throws CurrencyNotAllowed
     */
    public function assignChannels(AssignPriceListChannels $command): PriceListView;

    /**
     * Removes channels; one not assigned is ignored.
     *
     * @throws PriceListNotFound|PriceListArchived
     */
    public function unassignChannels(UnassignPriceListChannels $command): PriceListView;

    /** Archiving an archived list changes nothing. @throws PriceListNotFound */
    public function archive(ArchivePriceList $command): PriceListView;

    /** Restoring an active list changes nothing. @throws PriceListNotFound */
    public function restore(RestorePriceList $command): PriceListView;

    /** @throws PriceListNotFound */
    public function find(PriceListId $id): PriceListView;

    /** @return Page<PriceListView> */
    public function list(ListPriceLists $query): Page;
}
