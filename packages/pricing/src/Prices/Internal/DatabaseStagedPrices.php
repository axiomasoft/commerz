<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Contracts\StagedPrices;
use Commerz\Pricing\Prices\Data\DiscardStagedPrices;
use Commerz\Pricing\Prices\Data\ListStagedPrices;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Pricing\Prices\Data\PriceView;
use Commerz\Pricing\Prices\Data\PublishStagedPrices;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Data\SimulatedLinePrice;
use Commerz\Pricing\Prices\Data\SimulateStagedPrices;
use Commerz\Pricing\Prices\Data\StagedPriceId;
use Commerz\Pricing\Prices\Data\StagedPriceSimulation;
use Commerz\Pricing\Prices\Data\StagedPriceView;
use Commerz\Pricing\Prices\Data\StagePrice;
use Commerz\Pricing\Prices\Data\StagePrices;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use Commerz\Pricing\Prices\Events\PricesChanged;
use Commerz\Pricing\Prices\Exceptions\StagedPriceNotFound;
use Commerz\Pricing\Prices\Exceptions\StagedPriceStateConflict;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseStagedPrices implements StagedPrices
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private PriceWrites $writes,
        private StagedPriceOverlay $overlay,
        private QuotePipeline $quotes,
    ) {}

    #[Override]
    public function stage(StagePrice $command): StagedPriceView
    {
        return $this->transactions->run(function () use ($command): StagedPriceView {
            $list = $this->writes->lockList($command->priceList);
            $id = $this->insertStaged($list, $command, $command->batchCode);

            return $this->find(new StagedPriceId($id));
        });
    }

    #[Override]
    public function stageMany(StagePrices $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $list = $this->writes->lockList($command->priceList);
            $ids = [];

            foreach ($command->prices as $entry) {
                $ids[] = $this->insertStaged($list, $entry, $entry->batchCode ?? $command->batchCode);
            }

            $viewsById = $this->views($ids);

            return array_map(static fn (string $id): StagedPriceView => $viewsById[$id], $ids);
        });
    }

    #[Override]
    public function simulate(SimulateStagedPrices $query): StagedPriceSimulation
    {
        $stagedRows = $this->selectForSimulation($query->batchCode, $query->stagedPriceIds, $query->priceList);

        $overlayEntries = array_map(static function (object $row): array {
            $from = Rows::nullableString($row, 'valid_from');
            $until = Rows::nullableString($row, 'valid_until');

            return [
                'id' => Rows::string($row, 'id'),
                'price_list_id' => Rows::string($row, 'price_list_id'),
                'sellable_ref' => Rows::string($row, 'sellable_ref'),
                'customer_group' => Rows::nullableString($row, 'customer_group'),
                'min_quantity' => Rows::int($row, 'min_quantity'),
                'amount_minor' => Rows::int($row, 'amount_minor'),
                'compare_at_minor' => Rows::nullableInt($row, 'compare_at_minor'),
                'currency' => Rows::string($row, 'currency'),
                'valid_from' => $from === null ? null : new DateTimeImmutable($from),
                'valid_until' => $until === null ? null : new DateTimeImmutable($until),
            ];
        }, $stagedRows);

        $liveQuote = null;

        try {
            $liveQuote = $this->quotes->run($query->quoteInput);
        } catch (QuoteFailed $failed) {
            if (($failed->context()['cause'] ?? null) !== 'prices.price_not_found') {
                throw $failed;
            }
        }

        $simulatedQuote = $this->overlay->withEntries(
            $overlayEntries,
            fn () => $this->quotes->run($query->quoteInput),
        );

        $liveLinesById = [];

        if ($liveQuote !== null) {
            foreach ($liveQuote->lines as $liveLine) {
                $liveLinesById[$liveLine->lineId] = $liveLine;
            }
        }

        $currency = $query->quoteInput->context->currency;
        $zero = Money::zero($currency);
        $comparisons = [];

        foreach ($simulatedQuote->lines as $simLine) {
            $discount = $zero;

            foreach ($simLine->adjustments as $adjustment) {
                // Discounts in QuoteResult are negative Money adjustments.
                $discount = $discount->minus($adjustment->amount);
            }

            $effectiveNet = $simLine->subtotal->minus($discount);
            $liveMatch = $liveLinesById[$simLine->lineId] ?? null;

            $comparisons[] = new SimulatedLinePrice(
                lineId: $simLine->lineId,
                sellable: $simLine->sellable->ref,
                quantity: $simLine->quantity,
                liveUnitPrice: $liveMatch instanceof QuotedLine ? $liveMatch->unitPrice : null,
                stagedUnitPrice: $simLine->unitPrice,
                stagedCompareAt: $simLine->compareAt,
                stagedSubtotal: $simLine->subtotal,
                promotionDiscount: $discount,
                effectiveNetTotal: $effectiveNet,
                settledTotal: $simLine->total,
                stacksWithPromotions: $discount->isPositive(),
            );
        }

        $appliedPromotions = $simulatedQuote->appliedPromotions();
        $totalDiscount = $simulatedQuote->totals->discountTotal;

        return new StagedPriceSimulation(
            liveQuote: $liveQuote,
            simulatedQuote: $simulatedQuote,
            lines: $comparisons,
            appliedPromotions: $appliedPromotions,
            totalPromotionDiscount: $totalDiscount,
            hasStackedPromotions: $totalDiscount->isPositive() || $appliedPromotions !== [],
        );
    }

    #[Override]
    public function publish(PublishStagedPrices $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $rows = $this->lockMatchingRows($command->batchCode, $command->stagedPriceIds, $command->priceList);

            if ($rows === []) {
                return [];
            }

            // Lock target price lists in deterministic ID order first.
            $listIds = array_values(array_unique(array_map(static fn (object $r): string => Rows::string($r, 'price_list_id'), $rows)));
            sort($listIds);
            $lockedLists = [];

            foreach ($listIds as $listId) {
                $lockedLists[$listId] = $this->writes->lockList(new PriceListId($listId));
            }

            // Prepare SetPrice commands in deterministic lock order per list to avoid deadlocks.
            $items = [];

            foreach ($rows as $row) {
                $currency = Rows::string($row, 'currency');
                $compareAt = Rows::nullableInt($row, 'compare_at_minor');
                $from = Rows::nullableString($row, 'valid_from');
                $until = Rows::nullableString($row, 'valid_until');

                $items[] = [
                    'stagedId' => Rows::string($row, 'id'),
                    'listId' => Rows::string($row, 'price_list_id'),
                    'setPrice' => new SetPrice(
                        priceList: new PriceListId(Rows::string($row, 'price_list_id')),
                        sellable: SellableRef::fromString(Rows::string($row, 'sellable_ref')),
                        amount: Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
                        compareAt: $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
                        minQuantity: Rows::int($row, 'min_quantity'),
                        customerGroup: Rows::nullableString($row, 'customer_group'),
                        validFrom: $from === null ? null : new DateTimeImmutable($from),
                        validUntil: $until === null ? null : new DateTimeImmutable($until),
                    ),
                ];
            }

            $ordered = $items;
            uasort($ordered, static function (array $a, array $b): int {
                $byList = strcmp($a['listId'], $b['listId']);

                return $byList !== 0 ? $byList : PriceWrites::compare($a['setPrice'], $b['setPrice']);
            });

            $now = PgTimestamp::now();
            $publishedPriceIds = [];
            $sellablesByList = [];
            $stagedTable = $this->tables->table('staged_prices');

            foreach ($ordered as $position => $item) {
                $priceId = $this->writes->upsert($lockedLists[$item['listId']], $item['setPrice']);
                $publishedPriceIds[$position] = $priceId;
                $sellablesByList[$item['listId']][(string) $item['setPrice']->sellable] = true;

                $this->db()->table($stagedTable)
                    ->where('id', $item['stagedId'])
                    ->update([
                        'status' => StagedPriceStatus::Published->value,
                        'published_price_id' => $priceId,
                        'published_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            foreach ($sellablesByList as $listId => $sellables) {
                $this->events->dispatch(new PricesChanged(new PriceListId($listId), array_map(strval(...), array_keys($sellables))));
            }

            ksort($publishedPriceIds);
            $orderedIds = array_values($publishedPriceIds);
            $priceViews = $this->livePriceViews($orderedIds);

            return array_map(static fn (string $id): PriceView => $priceViews[$id], $orderedIds);
        });
    }

    #[Override]
    public function discard(DiscardStagedPrices $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $rows = $this->lockMatchingRows($command->batchCode, $command->stagedPriceIds, $command->priceList);

            if ($rows === []) {
                return [];
            }

            $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
            $now = PgTimestamp::now();

            $this->db()->table($this->tables->table('staged_prices'))
                ->whereIn('id', $ids)
                ->update([
                    'status' => StagedPriceStatus::Discarded->value,
                    'updated_at' => $now,
                ]);

            $viewsById = $this->views($ids);

            return array_map(static fn (string $id): StagedPriceView => $viewsById[$id], $ids);
        });
    }

    #[Override]
    public function find(StagedPriceId $id): StagedPriceView
    {
        $row = $this->db()->table($this->tables->table('staged_prices'))->where('id', $id->value)->first()
            ?? throw new StagedPriceNotFound($id->value);

        return self::view($row);
    }

    #[Override]
    public function list(ListStagedPrices $query): Page
    {
        $builder = $this->db()->table($this->tables->table('staged_prices'))
            ->orderBy('id')
            ->limit($query->first + 1);

        if ($query->priceList instanceof PriceListId) {
            $builder->where('price_list_id', $query->priceList->value);
        }

        if ($query->batchCode !== null) {
            $builder->where('batch_code', $query->batchCode);
        }

        if ($query->status instanceof StagedPriceStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->sellable instanceof SellableRef) {
            $builder->where('sellable_ref', (string) $query->sellable);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('id', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $rows = array_slice($rows, 0, $query->first);
        $views = array_map(self::view(...), $rows);

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->id->value) : null);
    }

    private function insertStaged(object $list, StagePrice $command, ?string $batchCode): string
    {
        if ($command->amount->currency->code !== Rows::string($list, 'currency')) {
            throw new InvalidCommand(
                'amount',
                'must be in '.Rows::string($list, 'currency').", the currency of price list '".Rows::string($list, 'code')."'",
                'pricing.currency_mismatch',
            );
        }

        $id = Uuid7::generate();
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('staged_prices'))->insert([
            'id' => $id,
            'batch_code' => $batchCode,
            'price_list_id' => $command->priceList->value,
            'sellable_ref' => (string) $command->sellable,
            'customer_group' => $command->customerGroup,
            'min_quantity' => $command->minQuantity,
            'amount_minor' => $command->amount->amountMinor,
            'compare_at_minor' => $command->compareAt?->amountMinor,
            'currency' => $command->amount->currency->code,
            'valid_from' => PriceWrites::format($command->validFrom),
            'valid_until' => PriceWrites::format($command->validUntil),
            'status' => StagedPriceStatus::Staged->value,
            'published_price_id' => null,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  list<StagedPriceId>  $stagedPriceIds
     * @return list<object>
     */
    private function selectForSimulation(?string $batchCode, array $stagedPriceIds, ?PriceListId $priceList): array
    {
        $builder = $this->db()->table($this->tables->table('staged_prices'))
            ->where('status', StagedPriceStatus::Staged->value)
            ->orderBy('id');

        $this->applyFilters($builder, $batchCode, $stagedPriceIds, $priceList);

        return array_values($builder->get()->all());
    }

    /**
     * @param  list<StagedPriceId>  $stagedPriceIds
     * @return list<object>
     */
    private function lockMatchingRows(?string $batchCode, array $stagedPriceIds, ?PriceListId $priceList): array
    {
        $builder = $this->db()->table($this->tables->table('staged_prices'))->orderBy('id');
        $this->applyFilters($builder, $batchCode, $stagedPriceIds, $priceList);

        if ($stagedPriceIds === []) {
            $builder->where('status', StagedPriceStatus::Staged->value);
        }

        $rows = array_values($builder->lock('for no key update')->get()->all());

        if ($stagedPriceIds !== []) {
            $foundIds = [];

            foreach ($rows as $row) {
                $id = Rows::string($row, 'id');
                $foundIds[$id] = true;
                $status = StagedPriceStatus::from(Rows::string($row, 'status'));

                if ($status !== StagedPriceStatus::Staged) {
                    throw new StagedPriceStateConflict($id, $status);
                }
            }

            foreach ($stagedPriceIds as $expectedId) {
                if (! isset($foundIds[$expectedId->value])) {
                    throw new StagedPriceNotFound($expectedId->value);
                }
            }
        }

        return $rows;
    }

    /** @param  list<StagedPriceId>  $stagedPriceIds */
    private function applyFilters(Builder $builder, ?string $batchCode, array $stagedPriceIds, ?PriceListId $priceList): void
    {
        if ($batchCode !== null) {
            $builder->where('batch_code', $batchCode);
        }

        if ($stagedPriceIds !== []) {
            $builder->whereIn('id', array_values(array_unique(array_map(static fn (StagedPriceId $id): string => $id->value, $stagedPriceIds))));
        }

        if ($priceList instanceof PriceListId) {
            $builder->where('price_list_id', $priceList->value);
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, StagedPriceView>
     */
    private function views(array $ids): array
    {
        $views = [];

        foreach ($this->db()->table($this->tables->table('staged_prices'))->whereIn('id', $ids)->get() as $row) {
            $views[Rows::string($row, 'id')] = self::view($row);
        }

        return $views;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, PriceView>
     */
    private function livePriceViews(array $ids): array
    {
        $views = [];

        foreach ($this->db()->table($this->tables->table('prices'))->whereIn('id', array_values(array_unique($ids)))->get() as $row) {
            $currency = Rows::string($row, 'currency');
            $compareAt = Rows::nullableInt($row, 'compare_at_minor');
            $from = Rows::nullableString($row, 'valid_from');
            $until = Rows::nullableString($row, 'valid_until');
            $id = Rows::string($row, 'id');

            $views[$id] = new PriceView(
                new PriceId($id),
                new PriceListId(Rows::string($row, 'price_list_id')),
                SellableRef::fromString(Rows::string($row, 'sellable_ref')),
                Rows::nullableString($row, 'customer_group'),
                Rows::int($row, 'min_quantity'),
                Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
                $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
                $from === null ? null : new DateTimeImmutable($from),
                $until === null ? null : new DateTimeImmutable($until),
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }

        return $views;
    }

    private static function view(object $row): StagedPriceView
    {
        $currency = Rows::string($row, 'currency');
        $compareAt = Rows::nullableInt($row, 'compare_at_minor');
        $from = Rows::nullableString($row, 'valid_from');
        $until = Rows::nullableString($row, 'valid_until');
        $publishedId = Rows::nullableString($row, 'published_price_id');
        $publishedAt = Rows::nullableString($row, 'published_at');

        return new StagedPriceView(
            id: new StagedPriceId(Rows::string($row, 'id')),
            batchCode: Rows::nullableString($row, 'batch_code'),
            priceList: new PriceListId(Rows::string($row, 'price_list_id')),
            sellable: SellableRef::fromString(Rows::string($row, 'sellable_ref')),
            customerGroup: Rows::nullableString($row, 'customer_group'),
            minQuantity: Rows::int($row, 'min_quantity'),
            amount: Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
            compareAt: $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
            validFrom: $from === null ? null : new DateTimeImmutable($from),
            validUntil: $until === null ? null : new DateTimeImmutable($until),
            status: StagedPriceStatus::from(Rows::string($row, 'status')),
            publishedPriceId: $publishedId === null ? null : new PriceId($publishedId),
            publishedAt: $publishedAt === null ? null : new DateTimeImmutable($publishedAt),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
