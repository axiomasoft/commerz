<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Internal;

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

/** `commerz.stock = unlimited`: everything is available, nothing is held or written. */
final readonly class UnlimitedStock implements StockAvailability, StockReservations
{
    public function check(CheckAvailability $query): AvailabilityAnswer
    {
        return new AvailabilityAnswer(array_map(
            static fn (AvailabilityLine $line): LineAvailability => new LineAvailability($line->sellable, $line->quantity, null),
            $query->lines,
        ));
    }

    public function reserve(ReserveStock $command): ReservationResult
    {
        return new ReservationResult($command->demand, array_map(
            static fn (ReserveLine $line): ReservedLine => new ReservedLine($line->sellable, $line->quantity),
            $command->lines,
        ));
    }

    public function release(ReleaseReservation $command): void {}

    public function consume(ConsumeReservation $command): void {}
}
