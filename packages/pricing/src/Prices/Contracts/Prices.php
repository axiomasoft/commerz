<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Pricing\Prices\Data\ListPrices;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\RemovePrice;
use Commerz\Pricing\Prices\Data\ReschedulePrice;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Exceptions\PriceNotFoundById;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;

/** Prices of sellables: a currency amount per price list, customer group, quantity step and validity window. */
#[Api(since: '0.1')]
interface Prices
{
    /**
     * @throws PriceListNotFound|PriceListArchived
     * @throws InvalidCommand the currency is not the one of the price list (`pricing.currency_mismatch`)
     * @throws PriceWindowOverlap
     */
    public function set(SetPrice $command): PriceView;

    /**
     * All or none. The views come in the order of the command.
     *
     * @return list<PriceView>
     *
     * @throws PriceListNotFound|PriceListArchived
     * @throws InvalidCommand a currency mismatch or two prices of one key and window (`pricing.duplicate_price`)
     * @throws PriceWindowOverlap
     */
    public function setMany(SetPrices $command): array;

    /**
     * @throws PriceNotFoundById
     * @throws PriceListArchived
     * @throws PriceWindowOverlap
     */
    public function reschedule(ReschedulePrice $command): PriceView;

    public function remove(RemovePrice $command): void;

    /** @return Page<PriceView> */
    public function list(ListPrices $query): Page;
}
