<?php

declare(strict_types=1);

namespace Commerz\Money;

use Brick\Math\BigInteger;
use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Splits an amount by weights so the parts sum exactly to the whole (largest remainder method). */
#[Api(since: '0.1')]
final readonly class MoneyAllocator
{
    /**
     * Zero weights get nothing; equal remainders go to the lowest index first.
     * A negative total is split by its magnitude and the sign restored.
     *
     * @param  list<int>  $weights  non-negative, with a positive sum
     * @return list<Money>
     */
    public static function allocate(Money $total, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidCommand('weights', 'must not be empty', 'money.invalid_weights');
        }

        $sum = BigInteger::zero();

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidCommand('weights', 'must not contain negative values', 'money.invalid_weights');
            }

            $sum = $sum->plus($weight);
        }

        if ($sum->isZero()) {
            throw new InvalidCommand('weights', 'must have a positive sum', 'money.invalid_weights');
        }

        $magnitude = BigInteger::of($total->amountMinor)->abs();
        $shares = [];
        $remainders = [];
        $assigned = BigInteger::zero();

        foreach ($weights as $index => $weight) {
            [$quotient, $remainder] = $magnitude->multipliedBy($weight)->quotientAndRemainder($sum);
            $shares[$index] = $quotient;
            $remainders[$index] = $remainder;
            $assigned = $assigned->plus($quotient);
        }

        $left = $magnitude->minus($assigned)->toInt();
        $order = array_keys($remainders);
        usort(
            $order,
            static function (int $a, int $b) use ($remainders): int {
                $byRemainder = $remainders[$b]->compareTo($remainders[$a]);

                return $byRemainder === 0 ? $a <=> $b : $byRemainder;
            },
        );

        foreach (array_slice($order, 0, $left) as $index) {
            $shares[$index] = $shares[$index]->plus(1);
        }

        $sign = $total->isNegative() ? -1 : 1;

        return array_values(array_map(
            static fn (BigInteger $share): Money => new Money($share->multipliedBy($sign)->toInt(), $total->currency),
            $shares,
        ));
    }
}
