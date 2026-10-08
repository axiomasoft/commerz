<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Shared shape checks of the stock port commands. */
#[Api(since: '0.1')]
final class StockLines
{
    /** A demand names what holds the stock, e.g. `order:01J…`: 1..191 bytes of printable text. */
    public static function assertDemand(string $demand): void
    {
        if (strlen($demand) > 191 || preg_match('/\A[^\p{Cc}\s][^\p{Cc}]*\z/u', $demand) !== 1) {
            throw new InvalidCommand('demand', 'must be 1..191 bytes of printable text', 'stock.invalid_demand');
        }
    }

    /** @param  list<AvailabilityLine|ReserveLine>  $lines */
    public static function assertUnique(array $lines): void
    {
        if ($lines === []) {
            throw new InvalidCommand('lines', 'must not be empty', 'stock.invalid_lines');
        }

        $seen = [];

        foreach ($lines as $line) {
            $key = (string) $line->sellable;

            if (isset($seen[$key])) {
                throw new InvalidCommand('lines', "'{$key}' appears more than once", 'stock.invalid_lines');
            }

            $seen[$key] = true;
        }
    }
}
