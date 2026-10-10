<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Allocation\Internal\AllocationPlanValidator;
use Commerz\Inventory\Common\Internal\InventoryConfig;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\Quantities;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Reservations\Data\ExpireReservations;
use Commerz\Inventory\Reservations\Data\ReservationView;
use Commerz\Inventory\Reservations\Enums\ReleaseCause;
use Commerz\Inventory\Reservations\Enums\ReservationState;
use Commerz\Inventory\Reservations\Events\StockConsumed;
use Commerz\Inventory\Reservations\Events\StockReleased;
use Commerz\Inventory\Reservations\Events\StockReserved;
use Commerz\Inventory\Reservations\Exceptions\ReservationConflict;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReservedLine;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Data\StockAllocation;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use LogicException;
use RuntimeException;

/**
 * Lock order of every command (knowledge/inventory-design#concurrency): the demand's header → (reserve: the items,
 * then the source locations, `for share`) → all levels the command touches in one ordered `for no key update`
 * ({@see StockLedger::lock()}) → the reservation lines by id. Foreign headers are taken only `skip locked`.
 */
final readonly class DatabaseReservations implements Reservations
{
    public function __construct(
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
        private StockLedger $ledger,
        private ReservationStore $store,
        private DemandResolver $demand,
        private LazyExpiry $expiry,
        private Contributions $contributions,
        private InventoryConfig $inventory,
        private AllocationPlanValidator $validator,
    ) {}

    public function reserve(ReserveStock $command): ReservationResult
    {
        $this->channels->assertActive($command->channel);

        return $this->transactions->run(fn (): ReservationResult => $this->ledger->within(fn (): ReservationResult => $this->place($command)));
    }

    public function release(ReleaseReservation $command): void
    {
        $this->transactions->run(fn () => $this->ledger->within(function () use ($command): void {
            $header = $this->store->lock($command->demand);

            if ($header === null) {
                return;
            }

            $id = Rows::string($header, 'id');
            $lines = $this->store->lines($id, $command->sellable, outstanding: true);

            if ($lines !== []) {
                $levels = $this->ledger->lock(self::pairs($lines));
                $locked = $this->store->lockLines(self::ids($lines));
                $remaining = $command->quantity ?? PHP_INT_MAX;
                $changes = [];

                // The latest allocations go back first.
                foreach (array_reverse(self::groups($locked)) as $group) {
                    if ($remaining === 0) {
                        break;
                    }

                    $take = min($remaining, max(array_map(static fn (LineRow $line): int => $line->outstanding(), $group)));

                    foreach ($group as $line) {
                        $units = min($take, $line->outstanding());

                        if ($units > 0) {
                            $this->store->count($line, 'released_units', $units);
                            $levels[$line->levelKey()] = $this->ledger->unhold(self::level($levels, $line), $units * $line->quantityPerUnit);
                            $changes[] = [$line, $units];
                        }
                    }

                    $remaining -= $take;
                }

                $this->events->dispatch(new StockReleased($command->demand, ReleaseCause::Released, LineRow::sellableUnits($changes)));
            }

            $this->store->closeIfSettled($id);
        }));
    }

    public function consume(ConsumeReservation $command): void
    {
        $this->transactions->run(fn () => $this->ledger->within(function () use ($command): void {
            $missing = new ReservationNotFound($command->demand, (string) $command->sellable);
            $header = $this->store->lock($command->demand) ?? throw $missing;
            $id = Rows::string($header, 'id');

            $lines = array_values(array_filter(
                $this->store->lines($id, $command->sellable),
                static fn (LineRow $line): bool => $command->locationCode === null || $line->locationCode === $command->locationCode,
            ));

            // A kit unit ships whole: an allocation gives the units every one of its component lines still holds.
            $taking = [];
            $available = 0;

            foreach (self::groups(self::keyed($lines)) as $group) {
                $units = min(array_map(static fn (LineRow $line): int => $line->outstanding(), $group));

                if ($units > 0) {
                    $available += $units;
                    array_push($taking, ...$group);
                }
            }

            // Untracked and unlimited sellables hold no lines: there is nothing to ship from the hinted location.
            if ($lines === [] && isset($this->unlimited([new ReserveLine($command->sellable, $command->quantity)])[(string) $command->sellable])) {
                $this->store->closeIfSettled($id);
                $this->events->dispatch(new StockConsumed($command->demand, $command->sellable, $command->quantity, $command->reference, []));

                return;
            }

            if ($available < $command->quantity) {
                throw $missing;
            }

            // Every level the demand still holds is locked now, in the global key order, not only this sellable's: a
            // document that ships several lines consumes them one call at a time in one transaction, and taking each
            // line's levels as it comes would lock them in line order — two orders of the same goods in opposite line
            // orders would deadlock (P8.4). Later calls of the transaction find their levels already held.
            $levels = $this->ledger->lock(self::pairs([...$this->store->lines($id, outstanding: true), ...$taking]));
            $groups = self::groups($this->store->lockLines(self::ids($taking)));
            $remaining = $command->quantity;
            $take = [];

            // Units on hand ship first, so a backorder allocation not yet received does not block stock elsewhere;
            // whatever is left comes from the remaining held units and fails if they are not on hand.
            foreach ([true, false] as $onHandOnly) {
                foreach ($groups as $index => $group) {
                    $units = min(array_map(static fn (LineRow $line): int => $onHandOnly
                        ? min($line->outstanding(), intdiv(self::level($levels, $line)->onHand, $line->quantityPerUnit))
                        : $line->outstanding(), $group)) - ($take[$index] ?? 0);
                    $units = min($remaining, max(0, $units));
                    $take[$index] = ($take[$index] ?? 0) + $units;
                    $remaining -= $units;
                }
            }

            $allocations = [];

            foreach ($groups as $index => $group) {
                $units = $take[$index] ?? 0;

                if ($units < 1) {
                    continue;
                }

                foreach ($group as $line) {
                    $this->store->count($line, 'consumed_units', $units);
                    $levels[$line->levelKey()] = $this->ledger->ship(self::level($levels, $line), $units * $line->quantityPerUnit, MovementKind::Sale, $command->reference);
                }

                $allocations[] = new StockAllocation($group[0]->locationCode, $units);
            }

            $this->store->closeIfSettled($id);
            $this->events->dispatch(new StockConsumed($command->demand, $command->sellable, $command->quantity, $command->reference, $allocations));
        }));
    }

    public function find(string $demand): ReservationView
    {
        return $this->store->view($demand) ?? throw new ReservationNotFound($demand, '*');
    }

    public function expire(ExpireReservations $command): int
    {
        $closed = 0;

        // One header per transaction: the sweeper never holds many levels at once.
        foreach ($this->store->expiredCandidates($command->limit ?? $this->inventory->expiryBatch) as $id) {
            $closed += $this->transactions->run(fn (): int => $this->ledger->within(fn (): int => $this->expireOne($id)));
        }

        return $closed;
    }

    /**
     * Reserves the stock of a transfer at its source: one line per item, held directly (no sellable, one unit per
     * unit), only from free stock and never as backorder. The caller runs inside {@see StockLedger::within()}, holds
     * the transfer row and has locked the items and the location `for share`.
     *
     * @internal for the transfers module
     *
     * @param  array<string, int>  $quantities  units by stock item id
     *
     * @throws InsufficientStock the free stock at the source (`stock_item:{id}`) is smaller than the quantity
     */
    public function holdTransfer(string $demand, string $from, array $quantities): void
    {
        $id = $this->store->insert($demand, null, null)
            ?? throw new LogicException("Demand '{$demand}' already holds a reservation.");
        ksort($quantities, SORT_STRING);

        $levels = $this->ledger->lock(array_map(static fn (string $item): array => [$item, $from], array_keys($quantities)));
        $levels = $this->expiry->run($levels, $id);
        $rows = [];

        foreach ($quantities as $item => $quantity) {
            $key = LevelRow::key($item, $from);
            $level = $levels[$key] ?? null;
            $free = $level instanceof LevelRow ? max(0, $level->view()->available) : 0;

            if (! $level instanceof LevelRow || $quantity > $free) {
                throw new InsufficientStock('stock_item:'.$item, $quantity, $free);
            }

            $levels[$key] = $this->ledger->hold($level, $quantity, false);
            $rows[] = [
                'sellable' => null,
                'item' => $item,
                'location' => $from,
                'level' => $level->id,
                'quantity_per_unit' => 1,
                'units' => $quantity,
                'position' => count($rows) + 1,
            ];
        }

        $this->store->insertLines($id, 1, $rows);
    }

    /**
     * Ships everything a transfer still holds from its source (movement `transfer_out`) and settles the header. Same
     * caller contract as {@see holdTransfer()}; lock order: header → levels → lines.
     *
     * @internal for the transfers module
     *
     * @throws InsufficientStock the reserved units are not all on hand (a count lowered the level)
     */
    public function shipTransfer(string $demand, string $reference): void
    {
        $this->settleTransfer($demand, 'consumed_units', fn (LevelRow $level, LineRow $line, int $units): LevelRow => $this->ledger->ship($level, $units * $line->quantityPerUnit, MovementKind::TransferOut, $reference));
    }

    /**
     * Gives the stock a transfer still holds back to its source and settles the header.
     *
     * @internal for the transfers module
     */
    public function releaseTransfer(string $demand): void
    {
        $this->settleTransfer($demand, 'released_units', fn (LevelRow $level, LineRow $line, int $units): LevelRow => $this->ledger->unhold($level, $units * $line->quantityPerUnit));
    }

    /** @param  callable(LevelRow, LineRow, int): LevelRow  $apply  moves the level for the outstanding units of a line */
    private function settleTransfer(string $demand, string $counter, callable $apply): void
    {
        $header = $this->store->lock($demand) ?? throw new LogicException("The reservation of '{$demand}' does not exist.");
        $id = Rows::string($header, 'id');
        $lines = $this->store->transferLines($id, outstanding: true);

        if ($lines === []) {
            throw new LogicException("Transfer demand '{$demand}' holds nothing.");
        }

        $levels = $this->ledger->lock(self::pairs($lines));

        foreach ($this->store->lockLines(self::ids($lines)) as $line) {
            $units = $line->outstanding();

            if ($units > 0) {
                $this->store->count($line, $counter, $units);
                $levels[$line->levelKey()] = $apply(self::level($levels, $line), $line, $units);
            }
        }

        $this->store->closeIfSettled($id);
    }

    private function expireOne(string $id): int
    {
        $demand = $this->store->lockExpired($id);

        if ($demand === null) {
            return 0;
        }

        $lines = $this->store->lines($id, outstanding: true);

        if ($lines !== []) {
            $levels = $this->ledger->lock(self::pairs($lines));
            [, $changes] = $this->expiry->expireLines(array_values($this->store->lockLines(self::ids($lines))), $levels);
            $this->events->dispatch(new StockReleased($demand, ReleaseCause::Expired, LineRow::sellableUnits($changes)));
        }

        $this->store->closeIfSettled($id);

        return 1;
    }

    private function place(ReserveStock $command): ReservationResult
    {
        $id = $this->store->insert($command->demand, $command->channel, $command->expiresAt);
        $generation = 1;
        $stale = [];

        if ($id === null) {
            $header = $this->store->lock($command->demand)
                ?? throw new RuntimeException("The reservation of '{$command->demand}' vanished under its lock.");
            $id = Rows::string($header, 'id');
            $channel = Rows::nullableString($header, 'channel_id');

            if ($channel !== $command->channel->value) {
                // A transfer's header has no channel: its demand is not a sales demand.
                throw new ReservationConflict($command->demand, $channel ?? 'none');
            }

            $lines = $this->store->lines($id);
            $outstanding = array_values(array_filter($lines, static fn (LineRow $line): bool => $line->outstanding() > 0));
            $active = Rows::string($header, 'state') === ReservationState::Active->value;
            $expired = Rows::bool($header, 'expired');

            // A live reservation is repeated even when it holds no lines (only untracked or unlimited sellables).
            if (($active && ! $expired) || array_any($lines, static fn (LineRow $line): bool => $line->consumed > 0)) {
                return $this->held($command, $lines);
            }

            // Closed, or expired with nothing shipped: release what is left and reserve anew.
            $stale = $outstanding;
            $generation = Rows::int($header, 'generation') + 1;
            $this->store->reactivate($id, $generation, $command->expiresAt);
        }

        $resolved = $this->demand->lock($command->lines);
        $stocked = [];

        foreach ($resolved as $line) {
            if ($line->unavailable) {
                throw new InsufficientStock((string) $line->sellable, $line->units, 0);
            }

            if ($line->stocked()) {
                foreach ($line->components ?? [] as $component) {
                    Quantities::mul($line->units, $component->quantityPerUnit)
                        ?? throw new InvalidCommand('quantity', 'overflows int64', 'inventory.invalid_quantity');
                }

                $stocked[(string) $line->sellable] = $line;
            }
        }

        $sources = $stocked === [] ? [] : $this->demand->lockSources($command->channel);
        $pairs = self::pairs($stale);
        $backorder = [];

        foreach ($stocked as $line) {
            foreach ($line->components ?? [] as $component) {
                foreach ($sources as $source) {
                    $pairs[] = [$component->item->value, $source['id']];

                    if ($component->backorder) {
                        $backorder[] = [$component->item->value, $source['id']];
                    }
                }
            }
        }

        // Backorder components may be held beyond stock anywhere, so every source needs a level to hold on.
        $this->ledger->ensureLevels($backorder);
        $levels = $this->ledger->lock($pairs);

        if ($stale !== []) {
            [$levels, $changes] = $this->expiry->expireLines(array_values($this->store->lockLines(self::ids($stale))), $levels);
            $this->events->dispatch(new StockReleased($command->demand, ReleaseCause::Expired, LineRow::sellableUnits($changes)));
        }

        $levels = $this->expiry->run($levels, $id);
        $held = $stocked === [] ? [] : $this->hold($id, $generation, $command->channel, $stocked, $sources, $levels);

        $view = $this->store->view($command->demand) ?? throw new RuntimeException("The reservation of '{$command->demand}' vanished.");
        $this->events->dispatch(new StockReserved($view));

        return new ReservationResult($command->demand, array_map(
            static fn (DemandLine $line): ReservedLine => new ReservedLine($line->sellable, $line->units, $held[(string) $line->sellable] ?? []),
            $resolved,
        ));
    }

    /**
     * Allocates the needs through the strategy on the locked levels, holds the stock and writes the lines.
     *
     * @param  array<string, DemandLine>  $stocked  by sellable ref
     * @param  list<array{id: string, code: string, priority: int}>  $sources
     * @param  array<string, LevelRow>  $levels
     * @return array<string, list<StockAllocation>> by sellable ref
     */
    private function hold(string $id, int $generation, ChannelId $channel, array $stocked, array $sources, array $levels): array
    {
        $codes = [];
        $sourceStock = [];

        foreach ($sources as $source) {
            $codes[$source['id']] = $source['code'];
            $free = [];

            foreach ($stocked as $line) {
                foreach ($line->components ?? [] as $component) {
                    $level = $levels[LevelRow::key($component->item->value, $source['id'])] ?? null;

                    if ($level !== null) {
                        $free[$component->item->value] = max(0, $level->view()->available);
                    }
                }
            }

            $sourceStock[] = new SourceStock(new LocationId($source['id']), $source['code'], $source['priority'], $free);
        }

        $request = new AllocationRequest($channel, array_values(array_map(static fn (DemandLine $line) => $line->need(), $stocked)), $sourceStock);
        $strategy = $this->contributions->get(AllocationStrategy::KIND, $this->inventory->allocationStrategy, AllocationStrategy::class);
        $plan = $strategy->allocate($request);
        $this->validator->validate($request, $plan);

        /** @var array<string, array{sellable: string, location: string, units: int}> $groups */
        $groups = [];
        $placed = [];

        foreach ($plan->allocations as $allocation) {
            $key = $allocation->sellable.'|'.$allocation->location->value;
            $groups[$key] ??= ['sellable' => (string) $allocation->sellable, 'location' => $allocation->location->value, 'units' => 0];
            // The validator bounds every sum by the need's units.
            $groups[$key]['units'] += $allocation->units;
            $placed[(string) $allocation->sellable] = ($placed[(string) $allocation->sellable] ?? 0) + $allocation->units;
        }

        foreach ($request->needs as $need) {
            if (($placed[(string) $need->sellable] ?? 0) < $need->units) {
                throw new InsufficientStock((string) $need->sellable, $need->units, $strategy->capacity($need, $sourceStock) ?? 0);
            }
        }

        $rows = [];
        $held = [];
        $position = 0;

        foreach ($groups as $group) {
            $position++;

            foreach ($stocked[$group['sellable']]->components ?? [] as $component) {
                $key = LevelRow::key($component->item->value, $group['location']);
                $level = $levels[$key] ?? throw new RuntimeException("The level {$key} is not locked.");
                $levels[$key] = $this->ledger->hold($level, $group['units'] * $component->quantityPerUnit, $component->backorder);
                $rows[] = [
                    'sellable' => $group['sellable'],
                    'item' => $component->item->value,
                    'location' => $group['location'],
                    'level' => $level->id,
                    'quantity_per_unit' => $component->quantityPerUnit,
                    'units' => $group['units'],
                    'position' => $position,
                ];
            }

            $held[$group['sellable']][] = new StockAllocation($codes[$group['location']], $group['units']);
        }

        $this->store->insertLines($id, $generation, $rows);

        return $held;
    }

    /**
     * What a repeated reserve returns: the units the demand still holds of each requested sellable, where.
     *
     * @param  list<LineRow>  $lines
     */
    private function held(ReserveStock $command, array $lines): ReservationResult
    {
        $bySellable = [];

        foreach ($lines as $line) {
            $bySellable[$line->sellable][$line->id] = $line;
        }

        $unlimited = null;
        $result = [];

        foreach ($command->lines as $line) {
            $ref = (string) $line->sellable;

            if (! isset($bySellable[$ref])) {
                $unlimited ??= $this->unlimited($command->lines);
                $result[] = new ReservedLine($line->sellable, isset($unlimited[$ref]) ? $line->quantity : 0);

                continue;
            }

            $allocations = [];
            $units = 0;

            foreach (self::groups($bySellable[$ref]) as $group) {
                $outstanding = min(array_map(static fn (LineRow $row): int => $row->outstanding(), $group));

                if ($outstanding > 0) {
                    $allocations[] = new StockAllocation($group[0]->locationCode, $outstanding);
                    $units += $outstanding;
                }
            }

            $result[] = new ReservedLine($line->sellable, $units, $allocations);
        }

        return new ReservationResult($command->demand, $result);
    }

    /**
     * @param  list<ReserveLine>  $lines
     * @return array<string, true> refs of the sellables stock does not limit
     */
    private function unlimited(array $lines): array
    {
        $refs = [];

        foreach ($this->demand->lock($lines) as $line) {
            if (! $line->unavailable && $line->components === null) {
                $refs[(string) $line->sellable] = true;
            }
        }

        return $refs;
    }

    /**
     * Lines grouped by allocation (sellable at a location), in allocation order.
     *
     * @param  array<string, LineRow>  $lines
     * @return list<non-empty-list<LineRow>>
     */
    private static function groups(array $lines): array
    {
        $rows = array_values($lines);
        usort($rows, static fn (LineRow $a, LineRow $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);
        $groups = [];

        foreach ($rows as $line) {
            $groups[$line->groupKey()][] = $line;
        }

        return array_values($groups);
    }

    /**
     * @param  list<LineRow>  $lines
     * @return array<string, LineRow>
     */
    private static function keyed(array $lines): array
    {
        $keyed = [];

        foreach ($lines as $line) {
            $keyed[$line->id] = $line;
        }

        return $keyed;
    }

    /**
     * @param  list<LineRow>  $lines
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(array $lines): array
    {
        return array_map(static fn (LineRow $line): array => [$line->item, $line->location], $lines);
    }

    /**
     * @param  list<LineRow>  $lines
     * @return list<string>
     */
    private static function ids(array $lines): array
    {
        return array_map(static fn (LineRow $line): string => $line->id, $lines);
    }

    /** @param  array<string, LevelRow>  $levels */
    private static function level(array $levels, LineRow $line): LevelRow
    {
        return $levels[$line->levelKey()] ?? throw new RuntimeException("The level of reservation line {$line->id} is not locked.");
    }
}
