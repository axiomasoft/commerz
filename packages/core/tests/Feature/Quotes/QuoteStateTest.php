<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Fakes\FakeSellables;

/**
 * @param  array<string, string>  $lines  line id => unit price (quantity 1)
 */
function qsState(array $lines): QuoteState
{
    $context = CommerceContext::for(
        ChannelId::fromString('0190a000-0000-7000-8000-000000000001'),
        Currency::of('RUB'),
        'ru',
        new DateTimeImmutable('2026-10-08T12:00:00Z'),
    );
    $inputs = [];

    foreach ($lines as $id => $price) {
        $inputs[] = new QuoteLineInput((string) $id, FakeSellables::snapshot("item-{$id}", Money::of($price, 'RUB')), 1);
    }

    $state = QuoteState::start(new QuoteInput($context, $inputs));

    foreach ($inputs as $input) {
        $state = $state->withLine(new QuoteLine($input->lineId, $input->sellable, 1, $input->sellable->basePrice, null, false, 'test'));
    }

    return $state;
}

function qsOrder(string $amount, string $explanation = 'promo'): Adjustment
{
    return Adjustment::order(Money::of($amount, 'RUB')->negate(), 'test', $explanation);
}

/** @param  list<Adjustment>  $shares */
function qsShares(array $shares): array
{
    return array_map(static fn (Adjustment $share): string => $share->lineId.'='.$share->amount->toDecimal(), $shares);
}

it('spreads an order adjustment over the lines to the kopeck, largest remainder first', function (): void {
    $state = qsState(['a' => '100.00', 'b' => '100.00', 'c' => '100.00'])->withAdjustment(qsOrder('100.00'));

    expect(qsShares($state->allocatedOrderAdjustments()))->toBe(['a=-33.34', 'b=-33.33', 'c=-33.33']);
});

it('spreads order adjustments one after another over what is left', function (): void {
    $state = qsState(['a' => '100.00', 'b' => '50.00'])
        ->withAdjustment(qsOrder('90.00', 'first'))
        ->withAdjustment(qsOrder('30.00', 'second'));
    $shares = $state->allocatedOrderAdjustments();

    expect(qsShares($shares))->toBe(['a=-60.00', 'b=-30.00', 'a=-20.00', 'b=-10.00'])
        ->and(array_map(static fn (Adjustment $share): string => $share->explanation, $shares))->toBe(['first', 'first', 'second', 'second']);
});

it('weights shares by the line net after line adjustments and keeps numeric-looking ids', function (): void {
    $state = qsState(['1' => '100.00', '2' => '100.00'])
        ->withAdjustment(Adjustment::line('1', Money::of('50.00', 'RUB')->negate(), 'test', 'line'))
        ->withAdjustment(qsOrder('30.00'));

    expect(qsShares($state->allocatedOrderAdjustments()))->toBe(['1=-10.00', '2=-20.00']);
});

it('omits zero shares and ignores line and delivery adjustments', function (): void {
    $state = qsState(['a' => '0.00', 'b' => '10.00'])
        ->withAdjustment(Adjustment::line('b', Money::of('1.00', 'RUB')->negate(), 'test', 'line'))
        ->withAdjustment(qsOrder('5.00'));

    expect(qsShares($state->allocatedOrderAdjustments()))->toBe(['b=-5.00'])
        ->and(qsState(['a' => '10.00'])->allocatedOrderAdjustments())->toBe([]);
});

it('refuses order adjustments above the items total', function (): void {
    $state = qsState(['a' => '10.00'])->withAdjustment(qsOrder('6.00'))->withAdjustment(qsOrder('5.00'));

    expect(fn () => $state->allocatedOrderAdjustments())->toThrow(
        fn (InvalidCommand $e): bool => $e->code() === 'quotes.negative_total',
    );
});

it('reports the delivery price after delivery adjustments', function (): void {
    $charge = new DeliveryCharge('courier', 'Courier', 'acme', Money::of('300.00', 'RUB'));
    $state = qsState(['a' => '10.00']);

    expect($state->deliveryNet())->toBeNull()
        ->and($state->withDelivery($charge)->deliveryNet()?->toDecimal())->toBe('300.00')
        ->and($state->withDelivery($charge)
            ->withAdjustment(Adjustment::delivery(Money::of('120.00', 'RUB')->negate(), 'test', 'free part'))
            ->withAdjustment(qsOrder('1.00'))
            ->deliveryNet()?->toDecimal())->toBe('180.00');
});
