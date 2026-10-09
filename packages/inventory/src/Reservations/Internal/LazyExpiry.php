<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Reservations\Enums\ReleaseCause;
use Commerz\Inventory\Reservations\Events\StockReleased;
use Commerz\Operations\Contracts\CommittedEvents;
use RuntimeException;

/**
 * Expired stock never blocks a reservation: before allocating, a reserve releases the outstanding lines of expired
 * reservations on the levels it already locked. It takes their headers only `skip locked` — a header someone holds
 * (its consume, release or the sweeper) is left alone — so it never waits and never closes a cycle. The header stays
 * active until the sweeper closes it, since its lines on other levels are not this reserve's to lock.
 */
final readonly class LazyExpiry
{
    public function __construct(
        private ReservationStore $store,
        private StockLedger $ledger,
        private CommittedEvents $events,
    ) {}

    /**
     * @param  array<string, LevelRow>  $levels  levels this command locked, by level key
     * @return array<string, LevelRow> the levels after the release
     */
    public function run(array $levels, string $except): array
    {
        $ids = array_values(array_map(static fn (LevelRow $level): string => $level->id, $levels));
        $headers = $this->store->lockExpiredOn($ids, $except);

        foreach ($this->store->lockOutstandingOn(array_keys($headers), $ids) as $header => $lines) {
            [$levels, $changes] = $this->expireLines($lines, $levels);
            $this->events->dispatch(new StockReleased($headers[$header], ReleaseCause::Expired, LineRow::sellableUnits($changes)));
        }

        return $levels;
    }

    /**
     * Marks the outstanding units of the lines expired and gives their stock back. The caller holds the lines'
     * header, levels and lines.
     *
     * @param  list<LineRow>  $lines
     * @param  array<string, LevelRow>  $levels  by level key
     * @return array{0: array<string, LevelRow>, 1: list<array{0: LineRow, 1: int}>} levels after, units expired per line
     */
    public function expireLines(array $lines, array $levels): array
    {
        $changes = [];

        foreach ($lines as $line) {
            $units = $line->outstanding();

            if ($units < 1) {
                continue;
            }

            $level = $levels[$line->levelKey()] ?? throw new RuntimeException("The level of reservation line {$line->id} is not locked.");
            $this->store->count($line, 'expired_units', $units);
            $levels[$line->levelKey()] = $this->ledger->unhold($level, $units * $line->quantityPerUnit);
            $changes[] = [$line, $units];
        }

        return [$levels, $changes];
    }
}
