<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

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

    /** A reference names the document that moves the stock, e.g. `return:01J…`: the same shape as a demand. */
    public static function assertReference(string $reference): void
    {
        if (strlen($reference) > 191 || preg_match('/\A[^\p{Cc}\s][^\p{Cc}]*\z/u', $reference) !== 1) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes of printable text', 'stock.invalid_reference');
        }
    }

    /** A location code names a stock location: `[a-z0-9][a-z0-9_.-]{0,63}`. */
    public static function assertLocation(?string $locationCode): void
    {
        if ($locationCode !== null && preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $locationCode) !== 1) {
            throw new InvalidCommand('locationCode', 'must match [a-z0-9][a-z0-9_.-]{0,63}', 'stock.invalid_location');
        }
    }

    /** @param  list<AvailabilityLine|ReserveLine|RestockLine|WriteOffLine>  $lines */
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
