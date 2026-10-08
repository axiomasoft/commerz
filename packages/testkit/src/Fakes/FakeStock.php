<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\AvailabilityAnswer;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\ConsumeReservation;
use Commerz\Ports\Stock\Data\LineAvailability;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Ports\Stock\Data\ReservationResult;
use Commerz\Ports\Stock\Data\ReservedLine;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Ports\Stock\Data\StockAllocation;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\Stock\Exceptions\ReservationNotFound;

/**
 * In-memory stock with the port's semantics: refs without a limit are unlimited; reserve is all-or-nothing and
 * idempotent per demand; consume takes held units out of stock. Every command is recorded.
 */
#[Api(since: '0.1')]
final class FakeStock implements StockAvailability, StockReservations
{
    public const string LOCATION = 'fake';

    /** @var array<string, int> ref => units on hand */
    private array $onHand = [];

    /** @var array<string, array<string, int>> demand => ref => units held */
    private array $held = [];

    /** @var list<ReserveStock> */
    public private(set) array $reserved = [];

    /** @var list<ReleaseReservation> */
    public private(set) array $released = [];

    /** @var list<ConsumeReservation> */
    public private(set) array $consumed = [];

    public function limit(SellableRef $sellable, int $onHand): self
    {
        $this->onHand[(string) $sellable] = $onHand;

        return $this;
    }

    /** Units free to reserve; null when unlimited. */
    public function available(SellableRef $sellable): ?int
    {
        $key = (string) $sellable;

        if (! isset($this->onHand[$key])) {
            return null;
        }

        return $this->onHand[$key] - array_sum(array_map(static fn (array $lines): int => $lines[$key] ?? 0, $this->held));
    }

    /** @return array<string, int> ref => units the demand holds */
    public function held(string $demand): array
    {
        return $this->held[$demand] ?? [];
    }

    public function onHand(SellableRef $sellable): ?int
    {
        return $this->onHand[(string) $sellable] ?? null;
    }

    public function check(CheckAvailability $query): AvailabilityAnswer
    {
        return new AvailabilityAnswer(array_map(
            fn (AvailabilityLine $line): LineAvailability => new LineAvailability($line->sellable, $line->quantity, $this->available($line->sellable)),
            $query->lines,
        ));
    }

    public function reserve(ReserveStock $command): ReservationResult
    {
        $this->reserved[] = $command;

        if (! isset($this->held[$command->demand])) {
            foreach ($command->lines as $line) {
                $available = $this->available($line->sellable);

                if ($available !== null && $available < $line->quantity) {
                    throw new InsufficientStock((string) $line->sellable, $line->quantity, $available);
                }
            }

            foreach ($command->lines as $line) {
                $this->held[$command->demand][(string) $line->sellable] = $line->quantity;
            }
        }

        $held = $this->held[$command->demand];

        return new ReservationResult($command->demand, array_map(
            fn (ReserveLine $line): ReservedLine => new ReservedLine(
                $line->sellable,
                $held[(string) $line->sellable] ?? 0,
                $this->onHand($line->sellable) === null ? [] : [new StockAllocation(self::LOCATION, $held[(string) $line->sellable] ?? 0)],
            ),
            $command->lines,
        ));
    }

    public function release(ReleaseReservation $command): void
    {
        $this->released[] = $command;

        if ($command->sellable === null) {
            unset($this->held[$command->demand]);

            return;
        }

        $key = (string) $command->sellable;
        $left = max(0, ($this->held[$command->demand][$key] ?? 0) - ($command->quantity ?? PHP_INT_MAX));
        $this->hold($command->demand, $key, $left);
    }

    public function consume(ConsumeReservation $command): void
    {
        $key = (string) $command->sellable;
        $held = $this->held[$command->demand][$key] ?? 0;

        if ($held < $command->quantity) {
            throw new ReservationNotFound($command->demand, $key);
        }

        $this->consumed[] = $command;
        $this->hold($command->demand, $key, $held - $command->quantity);

        if (isset($this->onHand[$key])) {
            $this->onHand[$key] -= $command->quantity;
        }
    }

    private function hold(string $demand, string $key, int $quantity): void
    {
        if ($quantity > 0) {
            $this->held[$demand][$key] = $quantity;

            return;
        }

        unset($this->held[$demand][$key]);

        if (($this->held[$demand] ?? []) === []) {
            unset($this->held[$demand]);
        }
    }
}
