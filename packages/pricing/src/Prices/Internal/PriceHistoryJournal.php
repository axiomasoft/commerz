<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Data\ListPriceHistory;
use Commerz\Pricing\Prices\Data\PriceHistoryEntry;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Pricing\Prices\Enums\PriceHistoryEvent;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Append-only writer and rolling 30-day EU Omnibus reader for `cmz_price_history` (INV-PRC-002).
 * Never updates or deletes journal rows.
 */
#[Internal]
final readonly class PriceHistoryJournal
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function record(
        string $priceId,
        string $priceListId,
        string $sellableRef,
        ?string $customerGroup,
        int $minQuantity,
        int $amountMinor,
        ?int $compareAtMinor,
        string $currency,
        PriceHistoryEvent $event,
        ?DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
    ): void {
        $this->db()->table($this->tables->table('price_history'))->insert([
            'price_id' => $priceId,
            'price_list_id' => $priceListId,
            'sellable_ref' => $sellableRef,
            'customer_group' => $customerGroup,
            'min_quantity' => $minQuantity,
            'amount_minor' => $amountMinor,
            'compare_at_minor' => $compareAtMinor,
            'currency' => $currency,
            'event_type' => $event->value,
            'valid_from' => Windows::format($validFrom),
            'valid_until' => Windows::format($validUntil),
            'recorded_at' => PgTimestamp::now(),
        ]);
    }

    /**
     * @return list<PriceHistoryEntry>
     */
    public function list(ListPriceHistory $query): array
    {
        $builder = $this->db()->table($this->tables->table('price_history'))
            ->where('sellable_ref', (string) $query->sellable)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($query->limit);

        if ($query->priceList instanceof PriceListId) {
            $builder->where('price_list_id', $query->priceList->value);
        }

        if ($query->currency instanceof Currency) {
            $builder->where('currency', $query->currency->code);
        }

        if ($query->since instanceof DateTimeImmutable) {
            $builder->where('recorded_at', '>=', Windows::format($query->since));
        }

        if ($query->until instanceof DateTimeImmutable) {
            $builder->where('recorded_at', '<=', Windows::format($query->until));
        }

        return array_values(array_map(static function (object $row): PriceHistoryEntry {
            $currency = Rows::string($row, 'currency');
            $compareAt = Rows::nullableInt($row, 'compare_at_minor');
            $from = Rows::nullableString($row, 'valid_from');
            $until = Rows::nullableString($row, 'valid_until');

            return new PriceHistoryEntry(
                id: Rows::int($row, 'id'),
                price: new PriceId(Rows::string($row, 'price_id')),
                priceList: new PriceListId(Rows::string($row, 'price_list_id')),
                sellable: SellableRef::fromString(Rows::string($row, 'sellable_ref')),
                customerGroup: Rows::nullableString($row, 'customer_group'),
                minQuantity: Rows::int($row, 'min_quantity'),
                amount: Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
                compareAt: $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
                event: PriceHistoryEvent::from(Rows::string($row, 'event_type')),
                validFrom: $from === null ? null : new DateTimeImmutable($from),
                validUntil: $until === null ? null : new DateTimeImmutable($until),
                recordedAt: Rows::date($row, 'recorded_at'),
            );
        }, $builder->get()->all()));
    }

    /**
     * Computes the rolling 30-day minimum prior price for every resolved winner that carries `compareAt` (INV-PRC-002)
     * in a single batched database read.
     *
     * @param  array<int, array{sellable: string, quantity: int, winner: ListedPrice}>  $discountedRequests  keyed by request index
     * @param  list<string>  $customerGroups
     * @return array<int, Money> keyed by request index
     */
    public function lowestLast30Days(
        array $discountedRequests,
        ChannelId $channel,
        Currency $currency,
        array $customerGroups,
        DateTimeImmutable $at,
    ): array {
        if ($discountedRequests === []) {
            return [];
        }

        $utcAt = $at->setTimezone(new DateTimeZone('UTC'));
        $windowStart = $utcAt->sub(new DateInterval('P30D'));

        $sellables = array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['sellable'],
            $discountedRequests,
        )));

        $rows = $this->db()->table($this->tables->table('price_history').' as h')
            ->join($this->tables->table('price_lists').' as l', 'l.id', '=', 'h.price_list_id')
            ->join($this->tables->table('price_list_channels').' as c', 'c.price_list_id', '=', 'l.id')
            ->where('c.channel_id', $channel->value)
            ->where('l.status', 'active')
            ->where('h.currency', $currency->code)
            ->whereIn('h.sellable_ref', $sellables)
            ->where('h.recorded_at', '<=', Windows::format($utcAt))
            ->orderBy('h.recorded_at')
            ->orderBy('h.id')
            ->get([
                'h.id',
                'h.price_id',
                'h.sellable_ref',
                'h.customer_group',
                'h.min_quantity',
                'h.amount_minor',
                'h.event_type',
                'h.valid_from',
                'h.valid_until',
                'h.recorded_at',
            ])
            ->all();

        $bySellable = [];

        foreach ($rows as $row) {
            $from = Rows::nullableString($row, 'valid_from');
            $until = Rows::nullableString($row, 'valid_until');
            $bySellable[Rows::string($row, 'sellable_ref')][] = [
                'id' => Rows::int($row, 'id'),
                'price_id' => Rows::string($row, 'price_id'),
                'customer_group' => Rows::nullableString($row, 'customer_group'),
                'min_quantity' => Rows::int($row, 'min_quantity'),
                'amount_minor' => Rows::int($row, 'amount_minor'),
                'event_type' => PriceHistoryEvent::from(Rows::string($row, 'event_type')),
                'valid_from' => $from === null ? null : new DateTimeImmutable($from)->setTimezone(new DateTimeZone('UTC')),
                'valid_until' => $until === null ? null : new DateTimeImmutable($until)->setTimezone(new DateTimeZone('UTC')),
                'recorded_at' => Rows::date($row, 'recorded_at')->setTimezone(new DateTimeZone('UTC')),
            ];
        }

        $results = [];

        foreach ($discountedRequests as $index => $entry) {
            $winner = $entry['winner'];
            $events = $bySellable[$entry['sellable']] ?? [];
            $minimumMinor = self::computeRollingMinimum(
                events: $events,
                quantity: $entry['quantity'],
                customerGroups: $customerGroups,
                windowStart: $windowStart,
                at: $utcAt,
                winnerPriceId: $winner->id,
                fallbackMinor: $winner->amountMinor,
            );

            $results[$index] = Money::ofMinor($minimumMinor, $currency);
        }

        return $results;
    }

    /**
     * @param  list<array{
     *     id: int,
     *     price_id: string,
     *     customer_group: string|null,
     *     min_quantity: int,
     *     amount_minor: int,
     *     event_type: PriceHistoryEvent,
     *     valid_from: DateTimeImmutable|null,
     *     valid_until: DateTimeImmutable|null,
     *     recorded_at: DateTimeImmutable,
     * }>  $events
     * @param  list<string>  $customerGroups
     */
    private static function computeRollingMinimum(
        array $events,
        int $quantity,
        array $customerGroups,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $at,
        string $winnerPriceId,
        int $fallbackMinor,
    ): int {
        $byPriceId = [];

        foreach ($events as $event) {
            if ($event['customer_group'] !== null && ! in_array($event['customer_group'], $customerGroups, true)) {
                continue;
            }

            if ($quantity < $event['min_quantity']) {
                continue;
            }

            $byPriceId[$event['price_id']][] = $event;
        }

        $latestWinnerEventId = null;

        if (isset($byPriceId[$winnerPriceId])) {
            $lastWinnerEvent = $byPriceId[$winnerPriceId][array_key_last($byPriceId[$winnerPriceId])];
            $latestWinnerEventId = $lastWinnerEvent['id'];
        }

        $priorMin = null;

        foreach ($byPriceId as $stream) {
            $count = count($stream);

            for ($i = 0; $i < $count; $i++) {
                $current = $stream[$i];

                if ($current['event_type'] === PriceHistoryEvent::Removed) {
                    continue;
                }

                if ($latestWinnerEventId !== null && $current['id'] === $latestWinnerEventId) {
                    continue;
                }

                $nextRecordedAt = $i + 1 < $count ? $stream[$i + 1]['recorded_at'] : null;
                $start = $current['valid_from'] ?? $current['recorded_at'];
                $end = self::minInstant($current['valid_until'], $nextRecordedAt);

                if ($current['valid_from'] !== null && $end !== null && $start >= $end) {
                    continue;
                }

                if ($end !== null && ($start <=> $end) === 0) {
                    if ($start >= $windowStart && $start <= $at) {
                        $priorMin = $priorMin === null ? $current['amount_minor'] : min($priorMin, $current['amount_minor']);
                    }

                    continue;
                }

                // Overlaps [windowStart, at] when start <= at and (end is open or end > windowStart).
                if ($start <= $at && ($end === null || $end > $windowStart)) {
                    $priorMin = $priorMin === null ? $current['amount_minor'] : min($priorMin, $current['amount_minor']);
                }
            }
        }

        return $priorMin ?? $fallbackMinor;
    }

    private static function minInstant(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a <= $b ? $a : $b;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
