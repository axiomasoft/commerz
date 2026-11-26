<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pricing\PriceLists\Data\PriceListFormula;
use Commerz\Pricing\PriceLists\Data\RoundingRule;
use Commerz\Pricing\PriceLists\Internal\DatabasePriceLists;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;

/**
 * Loads candidate prices of the given sellables in the active price lists assigned to the channel and currency,
 * including prices dynamically derived from parent formula price lists and `RoundingRule`s, plus any active
 * in-memory `StagedPriceOverlay` during `StagedPrices::simulate()`.
 *
 * @phpstan-type RawPriceEntry array{
 *     id: string,
 *     sellable: string,
 *     customerGroup: string|null,
 *     minQuantity: int,
 *     amountMinor: int,
 *     compareAtMinor: int|null,
 *     validFrom: DateTimeImmutable|null,
 *     validUntil: DateTimeImmutable|null,
 * }
 */
#[Internal]
final readonly class PriceCandidates
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private ?StagedPriceOverlay $overlay = null,
    ) {}

    /**
     * @param  list<string>  $sellables  sellable references
     * @return list<ListedPrice>
     */
    public function load(array $sellables, ChannelId $channel, Currency $currency): array
    {
        if ($sellables === []) {
            return [];
        }

        $uniqueSellables = array_values(array_unique($sellables));
        $db = $this->db();

        // Single query loads all active channel price lists and their direct prices for the requested sellables.
        $joinedRows = $db->table($this->tables->table('price_lists').' as l')
            ->join($this->tables->table('price_list_channels').' as c', 'c.price_list_id', '=', 'l.id')
            ->leftJoin($this->tables->table('prices').' as p', static function (JoinClause $join) use ($uniqueSellables): void {
                $join->on('p.price_list_id', '=', 'l.id')->whereIn('p.sellable_ref', $uniqueSellables);
            })
            ->where('c.channel_id', $channel->value)
            ->where('l.status', 'active')
            ->where('l.currency', $currency->code)
            ->orderBy('l.id')
            ->orderBy('p.id')
            ->get([
                'l.id as list_id',
                'l.code',
                'l.currency',
                'l.priority',
                'l.tax_included',
                'l.parent_price_list_id',
                'l.formula',
                'l.rounding_rule',
                'p.id as price_id',
                'p.sellable_ref',
                'p.customer_group',
                'p.min_quantity',
                'p.amount_minor',
                'p.compare_at_minor',
                'p.valid_from',
                'p.valid_until',
            ])
            ->all();

        if ($joinedRows === []) {
            return [];
        }

        /** @var array<string, object> $listsById */
        $listsById = [];
        $channelListIds = [];
        $missingParentIds = [];
        /** @var array<string, list<RawPriceEntry>> $explicitByList */
        $explicitByList = [];

        foreach ($joinedRows as $row) {
            $listId = Rows::string($row, 'list_id');

            if (! isset($listsById[$listId])) {
                $listsById[$listId] = (object) [
                    'id' => $listId,
                    'code' => Rows::string($row, 'code'),
                    'currency' => Rows::string($row, 'currency'),
                    'priority' => Rows::int($row, 'priority'),
                    'tax_included' => get_object_vars($row)['tax_included'] ?? null,
                    'parent_price_list_id' => Rows::nullableString($row, 'parent_price_list_id'),
                    'formula' => Rows::nullableString($row, 'formula'),
                    'rounding_rule' => Rows::nullableString($row, 'rounding_rule'),
                ];
                $channelListIds[] = $listId;
                $parentId = Rows::nullableString($row, 'parent_price_list_id');

                if ($parentId !== null && ! isset($listsById[$parentId])) {
                    $missingParentIds[$parentId] = $parentId;
                }
            }

            $priceId = Rows::nullableString($row, 'price_id');

            if ($priceId !== null) {
                $from = Rows::nullableString($row, 'valid_from');
                $until = Rows::nullableString($row, 'valid_until');
                $explicitByList[$listId][] = [
                    'id' => $priceId,
                    'sellable' => Rows::string($row, 'sellable_ref'),
                    'customerGroup' => Rows::nullableString($row, 'customer_group'),
                    'minQuantity' => Rows::int($row, 'min_quantity'),
                    'amountMinor' => Rows::int($row, 'amount_minor'),
                    'compareAtMinor' => Rows::nullableInt($row, 'compare_at_minor'),
                    'validFrom' => $from === null ? null : new DateTimeImmutable($from),
                    'validUntil' => $until === null ? null : new DateTimeImmutable($until),
                ];
            }
        }

        // Load ancestor chains only when at least one channel list derives from an external parent list.
        $ancestorListIds = [];

        for ($depth = 0; $depth < 16 && $missingParentIds !== []; $depth++) {
            $ancestors = $db->table($this->tables->table('price_lists'))
                ->whereIn('id', array_values($missingParentIds))
                ->where('status', 'active')
                ->get()
                ->all();

            $nextMissing = [];

            foreach ($ancestors as $ancestor) {
                $id = Rows::string($ancestor, 'id');
                $listsById[$id] = $ancestor;
                $ancestorListIds[] = $id;
                $parentId = property_exists($ancestor, 'parent_price_list_id') ? Rows::nullableString($ancestor, 'parent_price_list_id') : null;

                if ($parentId !== null && ! isset($listsById[$parentId])) {
                    $nextMissing[$parentId] = $parentId;
                }
            }

            $missingParentIds = $nextMissing;
        }

        if ($ancestorListIds !== []) {
            $ancestorRows = $db->table($this->tables->table('prices'))
                ->whereIn('price_list_id', $ancestorListIds)
                ->whereIn('sellable_ref', $uniqueSellables)
                ->orderBy('id')
                ->get([
                    'id',
                    'price_list_id',
                    'sellable_ref',
                    'customer_group',
                    'min_quantity',
                    'amount_minor',
                    'compare_at_minor',
                    'valid_from',
                    'valid_until',
                ])
                ->all();

            foreach ($ancestorRows as $row) {
                $from = Rows::nullableString($row, 'valid_from');
                $until = Rows::nullableString($row, 'valid_until');
                $explicitByList[Rows::string($row, 'price_list_id')][] = [
                    'id' => Rows::string($row, 'id'),
                    'sellable' => Rows::string($row, 'sellable_ref'),
                    'customerGroup' => Rows::nullableString($row, 'customer_group'),
                    'minQuantity' => Rows::int($row, 'min_quantity'),
                    'amountMinor' => Rows::int($row, 'amount_minor'),
                    'compareAtMinor' => Rows::nullableInt($row, 'compare_at_minor'),
                    'validFrom' => $from === null ? null : new DateTimeImmutable($from),
                    'validUntil' => $until === null ? null : new DateTimeImmutable($until),
                ];
            }
        }

        if ($this->overlay instanceof StagedPriceOverlay && $this->overlay->isActive()) {
            $explicitByList = $this->applyOverlay($explicitByList, array_keys($listsById), $uniqueSellables);
        }

        /** @var array<string, list<RawPriceEntry>> $resolvedByList */
        $resolvedByList = [];
        /** @var array<string, true> $visiting */
        $visiting = [];

        $candidates = [];

        foreach ($channelListIds as $listId) {
            $list = $listsById[$listId];
            $listCode = Rows::string($list, 'code');
            $priority = Rows::int($list, 'priority');
            $taxIncluded = DatabasePriceLists::nullableBoolColumn($list, 'tax_included');

            foreach ($this->resolveList($listId, $listsById, $explicitByList, $resolvedByList, $visiting) as $entry) {
                $candidates[] = new ListedPrice(
                    id: $entry['id'],
                    sellable: $entry['sellable'],
                    priceList: $listCode,
                    priority: $priority,
                    taxIncluded: $taxIncluded,
                    customerGroup: $entry['customerGroup'],
                    minQuantity: $entry['minQuantity'],
                    amountMinor: $entry['amountMinor'],
                    compareAtMinor: $entry['compareAtMinor'],
                    validFrom: $entry['validFrom'],
                    validUntil: $entry['validUntil'],
                );
            }
        }

        usort($candidates, static fn (ListedPrice $a, ListedPrice $b): int => strcmp($a->id, $b->id));

        return $candidates;
    }

    /**
     * @param  array<string, object>  $listsById
     * @param  array<string, list<RawPriceEntry>>  $explicitByList
     * @param  array<string, list<RawPriceEntry>>  $resolvedByList
     * @param  array<string, true>  $visiting
     * @return list<RawPriceEntry>
     */
    private function resolveList(
        string $listId,
        array $listsById,
        array $explicitByList,
        array &$resolvedByList,
        array &$visiting,
    ): array {
        if (isset($resolvedByList[$listId])) {
            return $resolvedByList[$listId];
        }

        if (isset($visiting[$listId]) || ! isset($listsById[$listId])) {
            return [];
        }

        $visiting[$listId] = true;
        $list = $listsById[$listId];
        $listCurrency = Currency::of(Rows::string($list, 'currency'));
        $roundingRule = property_exists($list, 'rounding_rule')
            ? DatabasePriceLists::decodeRoundingRule(Rows::nullableString($list, 'rounding_rule'))
            : null;
        $own = $explicitByList[$listId] ?? [];

        if ($roundingRule instanceof RoundingRule) {
            $own = array_map(
                static fn (array $entry): array => self::applyRoundingToEntry($entry, $listCurrency, $roundingRule),
                $own,
            );
        }

        $parentId = property_exists($list, 'parent_price_list_id')
            ? Rows::nullableString($list, 'parent_price_list_id')
            : null;

        if ($parentId === null || ! isset($listsById[$parentId])) {
            unset($visiting[$listId]);

            return $resolvedByList[$listId] = $own;
        }

        $parentList = $listsById[$parentId];
        $parentCurrency = Currency::of(Rows::string($parentList, 'currency'));
        $formula = property_exists($list, 'formula')
            ? DatabasePriceLists::decodeFormula(Rows::nullableString($list, 'formula'))
            : null;

        if ($formula === null && ! $parentCurrency->equals($listCurrency)) {
            unset($visiting[$listId]);

            return $resolvedByList[$listId] = $own;
        }

        $parentEntries = $this->resolveList($parentId, $listsById, $explicitByList, $resolvedByList, $visiting);
        $combined = $own;

        foreach ($parentEntries as $parentEntry) {
            if (self::hasOverlappingExplicit($own, $parentEntry)) {
                continue;
            }

            $parentMoney = Money::ofMinor($parentEntry['amountMinor'], $parentCurrency);
            $derivedAmount = $formula instanceof PriceListFormula
                ? $formula->evaluate($parentMoney, $listCurrency, $roundingRule)
                : ($roundingRule instanceof RoundingRule ? $roundingRule->apply($parentMoney) : $parentMoney);

            $derivedCompareMinor = null;

            if ($parentEntry['compareAtMinor'] !== null) {
                $parentCompare = Money::ofMinor($parentEntry['compareAtMinor'], $parentCurrency);
                $derivedCompare = $formula instanceof PriceListFormula
                    ? $formula->evaluate($parentCompare, $listCurrency, $roundingRule)
                    : ($roundingRule instanceof RoundingRule ? $roundingRule->apply($parentCompare) : $parentCompare);

                if ($derivedCompare->amountMinor > $derivedAmount->amountMinor) {
                    $derivedCompareMinor = $derivedCompare->amountMinor;
                }
            }

            $combined[] = [
                'id' => self::derivedPriceId($listId, $parentEntry['id']),
                'sellable' => $parentEntry['sellable'],
                'customerGroup' => $parentEntry['customerGroup'],
                'minQuantity' => $parentEntry['minQuantity'],
                'amountMinor' => $derivedAmount->amountMinor,
                'compareAtMinor' => $derivedCompareMinor,
                'validFrom' => $parentEntry['validFrom'],
                'validUntil' => $parentEntry['validUntil'],
            ];
        }

        unset($visiting[$listId]);

        return $resolvedByList[$listId] = $combined;
    }

    /**
     * @param  array<string, list<RawPriceEntry>>  $explicitByList
     * @param  list<string>  $listIds
     * @param  list<string>  $sellables
     * @return array<string, list<RawPriceEntry>>
     */
    private function applyOverlay(array $explicitByList, array $listIds, array $sellables): array
    {
        if (! $this->overlay instanceof StagedPriceOverlay) {
            return $explicitByList;
        }

        $listSet = array_flip($listIds);
        $sellableSet = array_flip($sellables);

        foreach ($this->overlay->entries() as $staged) {
            $listId = $staged['price_list_id'];
            $sellable = $staged['sellable_ref'];

            if (! isset($listSet[$listId], $sellableSet[$sellable])) {
                continue;
            }

            $stagedEntry = [
                'id' => $staged['id'],
                'sellable' => $sellable,
                'customerGroup' => $staged['customer_group'],
                'minQuantity' => $staged['min_quantity'],
                'amountMinor' => $staged['amount_minor'],
                'compareAtMinor' => $staged['compare_at_minor'],
                'validFrom' => $staged['valid_from'],
                'validUntil' => $staged['valid_until'],
            ];

            $existing = $explicitByList[$listId] ?? [];
            $filtered = array_values(array_filter(
                $existing,
                static fn (array $live): bool => ! self::sameKeyAndOverlaps($live, $stagedEntry),
            ));
            $filtered[] = $stagedEntry;
            $explicitByList[$listId] = $filtered;
        }

        return $explicitByList;
    }

    /**
     * @param  RawPriceEntry  $entry
     * @return RawPriceEntry
     */
    private static function applyRoundingToEntry(array $entry, Currency $currency, RoundingRule $rule): array
    {
        $roundedAmount = $rule->apply(Money::ofMinor($entry['amountMinor'], $currency));
        $roundedCompare = null;

        if ($entry['compareAtMinor'] !== null) {
            $candidateCompare = $rule->apply(Money::ofMinor($entry['compareAtMinor'], $currency));

            if ($candidateCompare->amountMinor > $roundedAmount->amountMinor) {
                $roundedCompare = $candidateCompare->amountMinor;
            }
        }

        $entry['amountMinor'] = $roundedAmount->amountMinor;
        $entry['compareAtMinor'] = $roundedCompare;

        return $entry;
    }

    /**
     * @param  list<RawPriceEntry>  $own
     * @param  RawPriceEntry  $parentEntry
     */
    private static function hasOverlappingExplicit(array $own, array $parentEntry): bool
    {
        return array_any($own, static fn (array $explicit): bool => self::sameKeyAndOverlaps($explicit, $parentEntry));
    }

    /**
     * @param  RawPriceEntry  $a
     * @param  RawPriceEntry  $b
     */
    private static function sameKeyAndOverlaps(array $a, array $b): bool
    {
        if ($a['sellable'] !== $b['sellable']
            || $a['customerGroup'] !== $b['customerGroup']
            || $a['minQuantity'] !== $b['minQuantity']) {
            return false;
        }

        if ($a['validFrom'] !== null && $b['validUntil'] !== null && $a['validFrom'] >= $b['validUntil']) {
            return false;
        }

        if ($b['validFrom'] !== null && $a['validUntil'] !== null && $b['validFrom'] >= $a['validUntil']) {
            return false;
        }

        return true;
    }

    /** Deterministic UUIDv5-shaped identifier for a formula-derived price candidate. */
    private static function derivedPriceId(string $childListId, string $parentPriceId): string
    {
        $hash = sha1('commerz.derived_price:'.$childListId.':'.$parentPriceId);

        return sprintf(
            '%s-%s-5%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
