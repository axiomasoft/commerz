<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Contracts;

use Commerz\Pagination\Page;
use Commerz\Pricing\PriceLists\Exceptions\PriceListArchived;
use Commerz\Pricing\PriceLists\Exceptions\PriceListNotFound;
use Commerz\Pricing\Prices\Data\DiscardStagedPrices;
use Commerz\Pricing\Prices\Data\ListStagedPrices;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\PublishStagedPrices;
use Commerz\Pricing\Prices\Data\SimulateStagedPrices;
use Commerz\Pricing\Prices\Data\StagedPriceId;
use Commerz\Pricing\Prices\Data\StagedPriceSimulation;
use Commerz\Pricing\Prices\Data\StagedPriceView;
use Commerz\Pricing\Prices\Data\StagePrice;
use Commerz\Pricing\Prices\Data\StagePrices;
use Commerz\Pricing\Prices\Exceptions\PriceWindowOverlap;
use Commerz\Pricing\Prices\Exceptions\StagedPriceNotFound;
use Commerz\Pricing\Prices\Exceptions\StagedPriceStateConflict;
use Packman\Attributes\Api;

/**
 * Staged price workflow (`cmz_staged_prices`): stage draft price changes, dry-run `simulate()` against active promotions,
 * then `publish()` atomically into `cmz_prices` or `discard()`.
 */
#[Api(since: '0.1')]
interface StagedPrices
{
    /**
     * @throws PriceListNotFound|PriceListArchived
     */
    public function stage(StagePrice $command): StagedPriceView;

    /**
     * @return list<StagedPriceView>
     *
     * @throws PriceListNotFound|PriceListArchived
     */
    public function stageMany(StagePrices $command): array;

    /**
     * Simulates staged prices through the full `QuotePipeline` (including active catalog/cart promotions and taxes)
     * without writing to `cmz_prices`.
     */
    public function simulate(SimulateStagedPrices $query): StagedPriceSimulation;

    /**
     * Atomically promotes matching `staged` rows into `cmz_prices` and marks them `published`.
     *
     * @return list<PriceView>
     *
     * @throws PriceListNotFound|PriceListArchived|PriceWindowOverlap|StagedPriceNotFound|StagedPriceStateConflict
     */
    public function publish(PublishStagedPrices $command): array;

    /**
     * Marks matching `staged` rows as `discarded`.
     *
     * @return list<StagedPriceView>
     *
     * @throws StagedPriceNotFound|StagedPriceStateConflict
     */
    public function discard(DiscardStagedPrices $command): array;

    /** @throws StagedPriceNotFound */
    public function find(StagedPriceId $id): StagedPriceView;

    /** @return Page<StagedPriceView> */
    public function list(ListStagedPrices $query): Page;
}
