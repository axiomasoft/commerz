<?php

declare(strict_types=1);

use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use Commerz\Pricing\Prices\Internal\ListedPrice;
use Commerz\Pricing\Prices\Internal\PriceSelection;
use Commerz\Pricing\Prices\Internal\RankedPrice;

function sel(
    string $id,
    int $amount,
    string $list = 'retail',
    int $priority = 0,
    ?string $group = null,
    int $min = 1,
    ?string $from = null,
    ?string $until = null,
    string $sellable = 'variant:tee',
): ListedPrice {
    return new ListedPrice(
        $id,
        $sellable,
        $list,
        $priority,
        null,
        $group,
        $min,
        $amount,
        null,
        $from === null ? null : new DateTimeImmutable($from),
        $until === null ? null : new DateTimeImmutable($until),
    );
}

/**
 * @param  list<ListedPrice>  $candidates
 * @param  list<string>  $groups
 * @return list<RankedPrice>
 */
function selRank(array $candidates, int $quantity = 1, array $groups = [], string $at = '2026-06-01T00:00:00Z'): array
{
    return PriceSelection::rank($candidates, 'variant:tee', $quantity, $groups, new DateTimeImmutable($at));
}

/** @param  list<RankedPrice>  $ranked */
function selIds(array $ranked): array
{
    return array_map(static fn (RankedPrice $entry): string => $entry->price->id, $ranked);
}

it('lets the list priority beat a lower amount', function (): void {
    $ranked = selRank([sel('a', 8000, 'sale', 10), sel('b', 5000, 'retail', 0), sel('c', 9000, 'contract', 20)]);

    expect(selIds($ranked))->toBe(['c', 'a', 'b'])
        ->and($ranked[0]->verdict)->toBe(CandidateVerdict::Selected)
        ->and($ranked[1]->verdict)->toBe(CandidateVerdict::Outranked)
        ->and($ranked[2]->verdict)->toBe(CandidateVerdict::Outranked);
});

it('picks the lowest amount within one priority level', function (): void {
    $ranked = selRank([sel('a', 9000), sel('b', 7000, 'other'), sel('c', 8000, 'third')]);

    expect(selIds($ranked))->toBe(['b', 'c', 'a']);
});

it('breaks an equal amount by group, quantity step, list code and id, in that order', function (): void {
    $equal = [
        sel('1-general', 100, 'a'),
        sel('2-group', 100, 'z', group: 'vip'),
        sel('3-step', 100, 'b', min: 5),
        sel('4-step-group', 100, 'y', group: 'vip', min: 5),
        sel('5-code', 100, 'c', min: 5),
        sel('6-id-b', 100, 'd'),
        sel('6-id-a', 100, 'd'),
    ];

    $ranked = selRank($equal, 10, ['vip']);

    // group first (vip before none), then the larger step, then list code, then id.
    expect(selIds($ranked))->toBe(['4-step-group', '2-group', '3-step', '5-code', '1-general', '6-id-a', '6-id-b']);
});

it('orders the codes byte-wise, not as numbers', function (): void {
    expect(selIds(selRank([sel('a', 100, '10'), sel('b', 100, '9')])))->toBe(['a', 'b'])
        ->and(selIds(selRank([sel('10', 100), sel('9', 100)])))->toBe(['10', '9']);
});

it('gives the same ranking whatever the order of the input', function (): void {
    $candidates = [
        sel('a', 90, 'retail', 0, null, 10),
        sel('b', 95, 'retail', 0, 'wholesale', 1),
        sel('c', 85, 'sale', 10),
        sel('d', 85, 'sale', 10, null, 1),
        sel('e', 85, 'sale', 10, 'vip'),
        sel('f', 120, 'retail', 0, 'gone'),
        sel('g', 70, 'retail', 0, null, 99),
    ];
    $expected = selIds(selRank($candidates, 10, ['wholesale', 'vip']));

    mt_srand(7);

    for ($i = 0; $i < 25; $i++) {
        shuffle($candidates);
        expect(selIds(selRank($candidates, 10, ['wholesale', 'vip'])))->toBe($expected);
    }

    expect($expected[0])->toBe('e');
});

it('keeps the window half-open at both bounds', function (): void {
    $price = sel('a', 100, from: '2026-06-01T00:00:00Z', until: '2026-07-01T00:00:00Z');

    expect(selRank([$price], at: '2026-05-31T23:59:59.999999Z')[0]->verdict)->toBe(CandidateVerdict::OutsideWindow)
        ->and(selRank([$price], at: '2026-06-01T00:00:00Z')[0]->verdict)->toBe(CandidateVerdict::Selected)
        ->and(selRank([$price], at: '2026-06-30T23:59:59.999999Z')[0]->verdict)->toBe(CandidateVerdict::Selected)
        ->and(selRank([$price], at: '2026-07-01T00:00:00Z')[0]->verdict)->toBe(CandidateVerdict::OutsideWindow);
});

it('treats a missing bound as open', function (): void {
    expect(selRank([sel('a', 100, until: '2026-07-01T00:00:00Z')], at: '2000-01-01T00:00:00Z')[0]->verdict)->toBe(CandidateVerdict::Selected)
        ->and(selRank([sel('a', 100, from: '2026-01-01T00:00:00Z')], at: '2099-01-01T00:00:00Z')[0]->verdict)->toBe(CandidateVerdict::Selected);
});

it('rejects a group price of a group the context is not in', function (): void {
    $ranked = selRank([sel('a', 50, group: 'vip'), sel('b', 100)], groups: ['wholesale']);

    expect(selIds($ranked))->toBe(['b', 'a'])
        ->and($ranked[0]->verdict)->toBe(CandidateVerdict::Selected)
        ->and($ranked[1]->verdict)->toBe(CandidateVerdict::GroupMismatch);
});

it('rejects a quantity step above the requested quantity', function (): void {
    $ranked = selRank([sel('a', 50, min: 10), sel('b', 100)], quantity: 9);

    expect($ranked[1]->verdict)->toBe(CandidateVerdict::BelowMinQuantity)
        ->and(selRank([sel('a', 50, min: 10)], quantity: 10)[0]->verdict)->toBe(CandidateVerdict::Selected);
});

it('names the first failing reason: group, then quantity, then window', function (): void {
    $price = sel('a', 100, group: 'vip', min: 5, from: '2027-01-01T00:00:00Z');

    expect(selRank([$price], 1)[0]->verdict)->toBe(CandidateVerdict::GroupMismatch)
        ->and(selRank([$price], 1, ['vip'])[0]->verdict)->toBe(CandidateVerdict::BelowMinQuantity)
        ->and(selRank([$price], 5, ['vip'])[0]->verdict)->toBe(CandidateVerdict::OutsideWindow);
});

it('lists the rejected prices after the applicable ones by list code, quantity step and id', function (): void {
    $ranked = selRank([
        sel('r3', 1, 'b', group: 'x'),
        sel('r2', 1, 'a', min: 50),
        sel('ok', 900),
        sel('r1', 1, 'a', min: 5),
        sel('r0', 1, 'a', min: 5, until: '2020-01-01T00:00:00Z'),
    ], quantity: 2);

    expect(selIds($ranked))->toBe(['ok', 'r0', 'r1', 'r2', 'r3'])
        ->and(PriceSelection::winner($ranked)?->id)->toBe('ok');
});

it('has no winner when nothing applies and ignores other sellables', function (): void {
    $ranked = selRank([sel('a', 100, group: 'vip'), sel('b', 1, sellable: 'variant:other')]);

    expect(PriceSelection::winner($ranked))->toBeNull()
        ->and(selIds($ranked))->toBe(['a'])
        ->and(PriceSelection::winner([]))->toBeNull();
});
