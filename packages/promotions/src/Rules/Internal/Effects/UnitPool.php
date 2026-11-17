<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Internal;

/**
 * The units of several lines as groups of equal value, so a quantity of a million costs two groups and not a million
 * units. A line's net is split the way `MoneyAllocator` splits it over equal weights: `net mod quantity` units cost one
 * minor unit more than the rest, and the values add up to the net exactly.
 */
#[Internal]
final readonly class UnitPool
{
    /** @param  list<array{int, int, int}>  $groups  (value, line index, count), sorted by value then line index */
    private function __construct(public array $groups, public int $units) {}

    /**
     * @param  list<array{int, int}>  $lines  (net in minor units, quantity) per line; the position is the line index
     */
    public static function of(array $lines): self
    {
        $groups = [];
        $units = 0;

        foreach ($lines as $index => [$net, $quantity]) {
            if ($net < 0 || $quantity < 1) {
                throw new InvalidCommand('lines', 'a line needs a non-negative net and a positive quantity', 'promotions.invalid_unit_pool');
            }

            $base = intdiv($net, $quantity);
            $dearer = $net % $quantity;

            if ($dearer > 0) {
                $groups[] = [$base + 1, $index, $dearer];
            }

            if ($quantity > $dearer) {
                $groups[] = [$base, $index, $quantity - $dearer];
            }

            if ($quantity > PHP_INT_MAX - $units) {
                throw new InvalidCommand('lines', 'the unit count does not fit an integer', 'promotions.invalid_unit_pool');
            }

            $units += $quantity;
        }

        usort($groups, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return new self($groups, $units);
    }

    /**
     * Takes the cheapest units, ties going to the lower line index.
     *
     * @return array<int, int> line index => the value of the units taken from that line, in minor units
     */
    public function cheapest(int $count): array
    {
        $taken = [];

        foreach ($this->groups as [$value, $index, $size]) {
            if ($count <= 0) {
                break;
            }

            $use = min($size, $count);
            $taken[$index] = ($taken[$index] ?? 0) + $value * $use;
            $count -= $use;
        }

        return $taken;
    }
}
