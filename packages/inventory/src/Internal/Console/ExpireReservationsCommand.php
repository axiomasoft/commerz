<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal\Console;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Reservations\Contracts\Reservations;
use Commerz\Inventory\Reservations\Data\ExpireReservations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;

/** The expiry sweeper: closes reservations past their `expiresAt` and gives their stock back. Schedule it, e.g. every minute. */
#[Description('Release expired Commerz stock reservations.')]
#[Signature('commerz:inventory:expire-reservations {--limit= : Reservations to close in this run (1..10000); defaults to commerz-inventory.expiry_batch}')]
#[Internal]
final class ExpireReservationsCommand extends Command
{
    public function handle(Reservations $reservations): int
    {
        $option = $this->option('limit');
        $limit = null;

        if ($option !== null) {
            $limit = filter_var($option, FILTER_VALIDATE_INT);

            if (! is_int($limit)) {
                $this->components->error('--limit must be an integer from 1 to 10000.');

                return self::FAILURE;
            }
        }

        try {
            $closed = $reservations->expire(new ExpireReservations($limit));
        } catch (InvalidCommand) {
            $this->components->error('--limit must be an integer from 1 to 10000.');

            return self::FAILURE;
        }

        $this->components->info("Closed {$closed} expired reservations.");

        return self::SUCCESS;
    }
}
