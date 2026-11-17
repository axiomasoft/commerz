<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Turns presented codes into adjustments: `line:<id>:<amount>:<promotion>[:<coupon>]`,
 * `order:<amount>:<promotion>[:<coupon>]`, `ship:<price>`, `shipoff:<amount>:<promotion>[:<coupon>]`.
 * An empty promotion (`-`) means a host adjustment without a promotion id.
 */
final readonly class ApCodeStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.applied';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CartPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $currency = $state->currency();

        foreach ($state->input->codes as $code) {
            $parts = explode(':', $code);
            $state = match ($parts[0]) {
                'line' => $state->withAdjustment(Adjustment::line($parts[1], Money::of($parts[2], $currency)->negate(), $this->key(), $code, self::promotion($parts[3]), $parts[4] ?? null)),
                'order' => $state->withAdjustment(Adjustment::order(Money::of($parts[1], $currency)->negate(), $this->key(), $code, self::promotion($parts[2]), $parts[3] ?? null)),
                'ship' => $state->withDelivery(new DeliveryCharge('courier', 'Courier', 'manual', Money::of($parts[1], $currency))),
                'shipoff' => $state->withAdjustment(Adjustment::delivery(Money::of($parts[1], $currency)->negate(), $this->key(), $code, self::promotion($parts[2]), $parts[3] ?? null)),
                default => $state,
            };
        }

        return $state;
    }

    private static function promotion(string $id): ?string
    {
        return $id === '-' ? null : $id;
    }
}

final class ApCodePlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.applied', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, ApCodeStage::class);
    }
}

/**
 * @param  array<string, string>  $lines  line id => unit price (quantity 1)
 * @param  list<string>  $codes
 */
function apQuote(array $lines, array $codes): QuoteResult
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

    return app(QuotePipeline::class)->run(new QuoteInput($context, $inputs, $codes));
}

/**
 * @param  list<AppliedPromotion>  $applied
 * @return list<array{string, ?string, string}>
 */
function apRows(array $applied): array
{
    return array_map(static fn (AppliedPromotion $a): array => [$a->promotionId, $a->couponCode, $a->discount->toDecimal()], $applied);
}

beforeEach(function (): void {
    config(['commerz.plugins' => [ApCodePlugin::class]]);
    Commerce::fake();
});

it('adds line, order and delivery adjustments of one promotion into one positive discount', function (): void {
    $result = apQuote(['a' => '100.00', 'b' => '100.00'], ['line:a:10.00:p1', 'order:20.00:p1', 'ship:50.00', 'shipoff:5.00:p1']);

    expect(apRows($result->appliedPromotions()))->toBe([['p1', null, '35.00']]);
});

it('does not count the line shares of an order-level adjustment again', function (): void {
    $result = apQuote(['a' => '100.00', 'b' => '100.00', 'c' => '100.00'], ['order:100.00:p1', 'line:b:30.00:p2']);

    $shares = array_sum(array_map(static fn (QuotedLine $line): int => count($line->adjustments), $result->lines));

    expect($shares)->toBe(4)
        ->and(apRows($result->appliedPromotions()))->toBe([['p2', null, '30.00'], ['p1', null, '100.00']])
        ->and($result->totals->discountTotal->toDecimal())->toBe('130.00');
});

it('ignores adjustments without a promotion id', function (): void {
    $result = apQuote(['a' => '100.00'], ['line:a:10.00:-', 'order:5.00:-', 'order:7.00:p1']);

    expect(apRows($result->appliedPromotions()))->toBe([['p1', null, '7.00']]);
});

it('lists promotions in order of first appearance: lines, then order, then delivery', function (): void {
    $result = apQuote(['a' => '100.00', 'b' => '100.00'], ['ship:50.00', 'shipoff:5.00:d', 'order:20.00:o', 'line:b:10.00:l', 'line:a:1.00:l2']);

    expect(array_column(apRows($result->appliedPromotions()), 0))->toBe(['l2', 'l', 'o', 'd']);
});

it('keeps numeric-looking promotion ids as strings', function (): void {
    $result = apQuote(['a' => '100.00'], ['order:10.00:2', 'order:5.00:1', 'order:1.00:2']);

    expect(apRows($result->appliedPromotions()))->toBe([['2', null, '11.00'], ['1', null, '5.00']]);
});

it('carries the coupon code of the promotion', function (): void {
    $result = apQuote(['a' => '100.00'], ['order:10.00:p1:SAVE', 'line:a:5.00:p1:SAVE']);

    expect(apRows($result->appliedPromotions()))->toBe([['p1', 'SAVE', '15.00']]);
});

it('refuses two different coupon codes on one promotion', function (): void {
    $result = apQuote(['a' => '100.00'], ['order:10.00:p1:ONE', 'shipoff:1.00:p1:TWO', 'ship:20.00']);

    expect(fn () => $result->appliedPromotions())
        ->toThrow(InvalidCommand::class, "promotion 'p1' carries two coupon codes");
});

it('refuses a coupon code against none on the same promotion', function (): void {
    $result = apQuote(['a' => '100.00'], ['order:10.00:p1', 'line:a:5.00:p1:SAVE']);

    try {
        $result->appliedPromotions();
        $this->fail('expected InvalidCommand');
    } catch (InvalidCommand $e) {
        expect($e->code())->toBe('quotes.inconsistent_promotion');
    }
});

it('gives an empty list for a quote without promotions', function (): void {
    expect(apQuote(['a' => '100.00'], [])->appliedPromotions())->toBe([])
        ->and(apQuote([], [])->appliedPromotions())->toBe([]);
});
