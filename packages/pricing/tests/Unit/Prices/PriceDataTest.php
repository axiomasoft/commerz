<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Data\ListPrices;
use Commerz\Pricing\Prices\Data\PriceId;
use Commerz\Pricing\Prices\Data\ReschedulePrice;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\Prices\Data\SetPrices;
use Commerz\Pricing\Prices\Internal\PriceWrites;
use PHPUnit\Framework\Assert;

/** @param array<string, mixed> $overrides */
function priceCommand(array $overrides = []): SetPrice
{
    $args = [
        'priceList' => PriceListId::fromString('018f0000-0000-7000-8000-000000000001'),
        'sellable' => SellableRef::of('variant', '1'),
        'amount' => Money::of('10.00', 'RUB'),
        'compareAt' => null,
        'minQuantity' => 1,
        'customerGroup' => null,
        'validFrom' => null,
        'validUntil' => null,
        ...$overrides,
    ];
    $priceList = $args['priceList'];
    $sellable = $args['sellable'];
    $amount = $args['amount'];
    $compareAt = $args['compareAt'];
    $minQuantity = $args['minQuantity'];
    $customerGroup = $args['customerGroup'];
    $validFrom = $args['validFrom'];
    $validUntil = $args['validUntil'];

    if (! $priceList instanceof PriceListId
        || ! $sellable instanceof SellableRef
        || ! $amount instanceof Money
        || ($compareAt !== null && ! $compareAt instanceof Money)
        || ! is_int($minQuantity)
        || ($customerGroup !== null && ! is_string($customerGroup))
        || ($validFrom !== null && ! $validFrom instanceof DateTimeImmutable)
        || ($validUntil !== null && ! $validUntil instanceof DateTimeImmutable)
    ) {
        throw new InvalidArgumentException('Price command overrides have the wrong type.');
    }

    return new SetPrice($priceList, $sellable, $amount, $compareAt, $minQuantity, $customerGroup, $validFrom, $validUntil);
}

it('accepts a plain price', function (): void {
    $price = priceCommand();

    expect($price->minQuantity)->toBe(1)->and($price->compareAt)->toBeNull()->and($price->customerGroup)->toBeNull();
});

it('checks the shape of a price', function (array $overrides, string $code): void {
    /** @var array<string, mixed> $overrides */
    try {
        priceCommand($overrides);
        Assert::fail('The command was accepted.');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe($code);
    }
})->with([
    'quantity step below 1' => [['minQuantity' => 0], 'pricing.invalid_quantity'],
    'negative amount' => [['amount' => Money::ofMinor(-1, 'RUB')], 'pricing.invalid_amount'],
    'compare-at equal to the amount' => [['compareAt' => Money::of('10.00', 'RUB')], 'pricing.invalid_compare_at'],
    'compare-at below the amount' => [['compareAt' => Money::of('9.00', 'RUB')], 'pricing.invalid_compare_at'],
    'compare-at in another currency' => [['compareAt' => Money::of('20.00', 'USD')], 'pricing.currency_mismatch'],
    'bad customer group' => [['customerGroup' => 'VIP group'], 'pricing.invalid_group'],
    'empty window' => [['validFrom' => new DateTimeImmutable('2026-02-01'), 'validUntil' => new DateTimeImmutable('2026-02-01')], 'pricing.invalid_window'],
    'reversed window' => [['validFrom' => new DateTimeImmutable('2026-03-01'), 'validUntil' => new DateTimeImmutable('2026-02-01')], 'pricing.invalid_window'],
]);

it('accepts zero and half-open windows', function (): void {
    $zero = priceCommand(['amount' => Money::zero('RUB')]);
    $from = priceCommand(['validFrom' => new DateTimeImmutable('2026-02-01')]);
    $until = priceCommand(['validUntil' => new DateTimeImmutable('2026-02-01')]);

    expect($zero->amount->amountMinor)->toBe(0)->and($from->validUntil)->toBeNull()->and($until->validFrom)->toBeNull();
});

it('bounds and checks a batch', function (): void {
    $list = PriceListId::fromString('018f0000-0000-7000-8000-000000000001');
    $other = PriceListId::fromString('018f0000-0000-7000-8000-000000000002');

    expect(fn () => new SetPrices($list, []))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetPrices($list, array_fill(0, SetPrices::MAX + 1, priceCommand())))->toThrow(InvalidCommand::class)
        ->and(fn () => new SetPrices($list, [priceCommand(['priceList' => $other])]))->toThrow(InvalidCommand::class)
        ->and(new SetPrices($list, array_fill(0, SetPrices::MAX, priceCommand()))->prices)->toHaveCount(SetPrices::MAX);
});

it('checks the window of a reschedule and the page size of a list', function (): void {
    $price = PriceId::fromString('018f0000-0000-7000-8000-000000000003');
    $list = PriceListId::fromString('018f0000-0000-7000-8000-000000000001');

    expect(fn () => new ReschedulePrice($price, new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-01-01')))
        ->toThrow(InvalidCommand::class)
        ->and(new ReschedulePrice($price)->validFrom)->toBeNull()
        ->and(fn () => new ListPrices($list, first: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListPrices($list, first: 201))->toThrow(InvalidCommand::class);
});

it('checks a price list command', function (): void {
    $rub = Currency::of('RUB');

    expect(fn () => new CreatePriceList('Bad Code', 'x', $rub))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreatePriceList('ok', ' ', $rub))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreatePriceList('ok', 'x', $rub, priority: 2_147_483_648))->toThrow(InvalidCommand::class)
        ->and(new CreatePriceList('ok', 'x', $rub, priority: -5)->priority)->toBe(-5);
});

it('orders a batch by sellable, group, quantity step and window with open bounds first', function (): void {
    $commands = [
        priceCommand(['sellable' => SellableRef::of('variant', 'b')]),
        priceCommand(['customerGroup' => 'vip']),
        priceCommand(['minQuantity' => 5]),
        priceCommand(['validFrom' => new DateTimeImmutable('2026-05-01')]),
        priceCommand(),
        priceCommand(['validFrom' => new DateTimeImmutable('2026-02-01'), 'validUntil' => new DateTimeImmutable('2026-03-01')]),
    ];
    usort($commands, PriceWrites::compare(...));

    $describe = static fn (SetPrice $c): string => implode('/', [
        $c->sellable->id, $c->customerGroup ?? '-', $c->minQuantity, $c->validFrom?->format('m') ?? '-',
    ]);

    expect(array_map($describe, $commands))->toBe([
        '1/-/1/-', '1/-/1/02', '1/-/1/05', '1/-/5/-', '1/vip/1/-', 'b/-/1/-',
    ]);
});

it('tells windows of one key apart by identity', function (): void {
    expect(PriceWrites::identity(priceCommand()))->toBe(PriceWrites::identity(priceCommand(['amount' => Money::of('99.00', 'RUB')])))
        ->and(PriceWrites::identity(priceCommand()))->not->toBe(PriceWrites::identity(priceCommand(['customerGroup' => 'vip'])))
        ->and(PriceWrites::identity(priceCommand()))->not->toBe(PriceWrites::identity(priceCommand(['validFrom' => new DateTimeImmutable('2026-02-01')])))
        ->and(PriceWrites::identity(priceCommand(['validFrom' => new DateTimeImmutable('2026-02-01T03:00:00+03:00')])))
        ->toBe(PriceWrites::identity(priceCommand(['validFrom' => new DateTimeImmutable('2026-02-01T00:00:00Z')])));
});
