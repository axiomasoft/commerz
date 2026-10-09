<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use DateTimeImmutable;

/**
 * Which price of a sellable wins (PRC-02): a pure function of the candidates and the request, with a total order, so
 * the same data always gives the same price whatever order the database returned the rows in.
 *
 * Among the applicable prices: price list priority (higher first), then the lowest amount, then a price meant for a
 * customer group over a general one, then the larger quantity step, then list code and id. Priority is the seller's
 * explicit will; within a level the buyer never pays more than an offer they qualify for.
 */
final class PriceSelection
{
    /**
     * @param  list<ListedPrice>  $candidates  may hold other sellables; they are ignored
     * @param  list<string>  $groups  customer groups of the context
     * @return list<RankedPrice> the applicable prices in ranking order (the first is `Selected`), then the others
     */
    public static function rank(array $candidates, string $sellable, int $quantity, array $groups, DateTimeImmutable $at): array
    {
        $applicable = [];
        $rejected = [];

        foreach ($candidates as $candidate) {
            if ($candidate->sellable !== $sellable) {
                continue;
            }

            $verdict = self::rejection($candidate, $quantity, $groups, $at);

            if ($verdict === null) {
                $applicable[] = $candidate;
            } else {
                $rejected[] = new RankedPrice($candidate, $verdict);
            }
        }

        usort($applicable, self::outranks(...));
        usort($rejected, static fn (RankedPrice $a, RankedPrice $b): int => self::firstDifference(
            strcmp($a->price->priceList, $b->price->priceList),
            $a->price->minQuantity <=> $b->price->minQuantity,
            strcmp($a->price->id, $b->price->id),
        ));

        $ranked = [];

        foreach ($applicable as $position => $candidate) {
            $ranked[] = new RankedPrice($candidate, $position === 0 ? CandidateVerdict::Selected : CandidateVerdict::Outranked);
        }

        return [...$ranked, ...$rejected];
    }

    /**
     * The first applicable price, if any.
     *
     * @param  list<RankedPrice>  $ranked
     */
    public static function winner(array $ranked): ?ListedPrice
    {
        $first = $ranked[0] ?? null;

        return $first instanceof RankedPrice && $first->verdict === CandidateVerdict::Selected ? $first->price : null;
    }

    /** @param  list<string>  $groups */
    private static function rejection(ListedPrice $price, int $quantity, array $groups, DateTimeImmutable $at): ?CandidateVerdict
    {
        return match (true) {
            $price->customerGroup !== null && ! in_array($price->customerGroup, $groups, true) => CandidateVerdict::GroupMismatch,
            $quantity < $price->minQuantity => CandidateVerdict::BelowMinQuantity,
            ($price->validFrom !== null && $at < $price->validFrom) || ($price->validUntil !== null && $at >= $price->validUntil) => CandidateVerdict::OutsideWindow,
            default => null,
        };
    }

    /** Negative when `$a` ranks before `$b`; ids are unique, so never zero for two different prices. */
    private static function outranks(ListedPrice $a, ListedPrice $b): int
    {
        // Byte-wise strcmp: `<=>` would compare numeric-looking codes as numbers.
        return self::firstDifference(
            $b->priority <=> $a->priority,
            $a->amountMinor <=> $b->amountMinor,
            ($b->customerGroup !== null) <=> ($a->customerGroup !== null),
            $b->minQuantity <=> $a->minQuantity,
            strcmp($a->priceList, $b->priceList),
            strcmp($a->id, $b->id),
        );
    }

    /** The first non-zero comparison of a lexicographic order. */
    private static function firstDifference(int ...$comparisons): int
    {
        return array_find($comparisons, static fn (int $comparison): bool => $comparison !== 0) ?? 0;
    }
}
