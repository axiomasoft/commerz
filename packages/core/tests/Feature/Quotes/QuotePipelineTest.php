<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Facades\Commerz;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;

/** Records that it ran: "<priced lines>|<settled or open>". */
final readonly class QtRecordingStage implements QuoteStage
{
    public function __construct(private string $key, private QuoteAnchor $anchor, private StagePosition $position) {}

    public function key(): string
    {
        return $this->key;
    }

    public function anchor(): QuoteAnchor
    {
        return $this->anchor;
    }

    public function position(): StagePosition
    {
        return $this->position;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $progress = count($state->lines).'|'.($state->totals === null ? 'open' : 'settled');

        return $state->withRejection(new Rejection($this->key, 'ran', $progress));
    }
}

/**
 * Turns presented codes into amounts: `order:<amount>`, `line:<id>:<amount>`, `ship:<price>`, `shipoff:<amount>`,
 * `tax:<id>:<amount>` (excluded) and `boom` (throws).
 */
final readonly class QtCodeStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.codes';
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
                'order' => $state->withAdjustment(Adjustment::order(Money::of($parts[1], $currency)->negate(), $this->key(), $code, 'promo-1')),
                'line' => $state->withAdjustment(Adjustment::line($parts[1], Money::of($parts[2], $currency)->negate(), $this->key(), $code)),
                'ship' => $state->withDelivery(new DeliveryCharge('courier', 'Courier', 'manual', Money::of($parts[1], $currency))),
                'shipoff' => $state->withAdjustment(Adjustment::delivery(Money::of($parts[1], $currency)->negate(), $this->key(), $code)),
                'tax' => $state->withTaxLine(new TaxLine(AdjustmentTarget::Line, $parts[1], 'vat', Rate::bp(2000), Money::of($parts[2], $currency), false)),
                'boom' => throw new RuntimeException('stage exploded'),
                default => $state,
            };
        }

        return $state;
    }
}

/** Changes amounts after the totals stage when the code `late` is presented. */
final readonly class QtLateStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.late';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Totals;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function apply(QuoteState $state): QuoteState
    {
        if (! in_array('late', $state->input->codes, true)) {
            return $state;
        }

        return $state->withAdjustment(Adjustment::order(Money::ofMinor(-1, $state->currency()), $this->key(), 'late'));
    }
}

final class QtCodePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.codes', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->quoteStage(QtCodeStage::class);
        $registrar->quoteStage(new QtLateStage);
    }
}

final class QtOrderPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.order', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        foreach ([
            ['totals.after', QuoteAnchor::Totals, StagePosition::After],
            ['zeta.after-base', QuoteAnchor::Base, StagePosition::After],
            ['tax.before', QuoteAnchor::Tax, StagePosition::Before],
            ['alpha.after-base', QuoteAnchor::Base, StagePosition::After],
            ['cart.before', QuoteAnchor::CartPromotions, StagePosition::Before],
            ['base.before', QuoteAnchor::Base, StagePosition::Before],
            ['tax', QuoteAnchor::Tax, StagePosition::At],
            ['delivery.after', QuoteAnchor::Delivery, StagePosition::After],
        ] as [$key, $anchor, $position]) {
            $registrar->quoteStage(new QtRecordingStage($key, $anchor, $position));
        }
    }
}

final class QtTwoAtPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.two-at', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->quoteStage(new QtRecordingStage('acme.tax', QuoteAnchor::Tax, StagePosition::At));
        $registrar->quoteStage(new QtRecordingStage('other.tax', QuoteAnchor::Tax, StagePosition::At));
    }
}

final class QtBaseAtPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.base-at', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->quoteStage(new QtRecordingStage('acme.base', QuoteAnchor::Base, StagePosition::At));
    }
}

/**
 * @param  array<string, array{0: string, 1?: int}>  $lines  line id => [unit price, quantity]
 * @param  list<string>  $codes
 */
function qtInput(array $lines, array $codes = [], ?DateTimeImmutable $at = null): QuoteInput
{
    $context = CommerceContext::for(
        ChannelId::fromString('0190a000-0000-7000-8000-000000000001'),
        Currency::of('RUB'),
        'ru',
        $at ?? new DateTimeImmutable('2026-10-08T12:00:00Z'),
    );

    $inputs = [];

    foreach ($lines as $id => $line) {
        $inputs[] = new QuoteLineInput((string) $id, FakeSellables::snapshot("item-{$id}", Money::of($line[0], 'RUB')), $line[1] ?? 1);
    }

    return new QuoteInput($context, $inputs, $codes);
}

function qtRun(QuoteInput $input): QuoteResult
{
    return app(QuotePipeline::class)->run($input);
}

/** @return list<string> */
function qtDecimals(array $amounts): array
{
    return array_map(static fn (Money $money): string => $money->toDecimal(), $amounts);
}

beforeEach(function (): void {
    config(['commerz.plugins' => [QtCodePlugin::class]]);
    Commerce::fake();
});

it('runs stages by anchor, then Before → At → After, then key', function (): void {
    config(['commerz.plugins' => [QtOrderPlugin::class]]);

    $result = qtRun(qtInput(['a' => ['10.00']]));

    expect(array_map(static fn (Rejection $r): string => "{$r->source}={$r->message}", $result->rejections))->toBe([
        'base.before=0|open',
        'alpha.after-base=1|open',
        'zeta.after-base=1|open',
        'cart.before=1|open',
        'delivery.after=1|open',
        'tax.before=1|open',
        'tax=1|open',
        'totals.after=1|settled',
    ]);
});

it('refuses two At stages on one anchor', function (): void {
    config(['commerz.plugins' => [QtTwoAtPlugin::class]]);

    expect(fn () => qtRun(qtInput(['a' => ['10.00']])))
        ->toThrow(InvalidConfiguration::class, "quote stages 'acme.tax' and 'other.tax' both run At anchor 'tax'");
});

it('refuses a plugin stage At an anchor the core owns', function (): void {
    config(['commerz.plugins' => [QtBaseAtPlugin::class]]);

    expect(fn () => qtRun(qtInput(['a' => ['10.00']])))
        ->toThrow(InvalidConfiguration::class, "quote stages 'acme.base' and 'base' both run At anchor 'base'");
});

it('is deterministic: the same input gives the same result and fingerprint', function (): void {
    $input = qtInput(['a' => ['10.00', 2], 'b' => ['5.50']], ['order:3.00']);

    $first = qtRun($input);
    $second = qtRun(qtInput(['a' => ['10.00', 2], 'b' => ['5.50']], ['order:3.00']));

    expect($second)->toEqual($first)
        ->and($first->fingerprint)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(qtRun(qtInput(['a' => ['10.00', 3], 'b' => ['5.50']], ['order:3.00']))->fingerprint)->not->toBe($first->fingerprint)
        ->and(qtRun(qtInput(['a' => ['10.00', 2], 'b' => ['5.50']], ['order:3.00'], new DateTimeImmutable('2026-10-09T12:00:00Z')))->fingerprint)->not->toBe($first->fingerprint);
});

it('prices lines with the PriceResolver and falls back to the sellable base price', function (): void {
    $commerce = Commerce::fake();
    $commerce->prices->set(SellableRef::of('fake', 'item-a'), Money::of('7.00', 'RUB'), Money::of('9.00', 'RUB'));

    $result = qtRun(qtInput(['a' => ['10.00', 3], 'b' => ['2.50', 2]]));

    expect(qtDecimals(array_map(static fn ($l) => $l->unitPrice, $result->lines)))->toBe(['7.00', '2.50'])
        ->and(array_map(static fn ($l) => $l->priceSource, $result->lines))->toBe(['fake', 'sellable'])
        ->and($result->lines[0]->compareAt?->toDecimal())->toBe('9.00')
        ->and(qtDecimals([$result->totals->subtotal, $result->total]))->toBe(['26.00', '26.00']);
});

it('spreads a 100.00 order discount over three lines to the kopeck', function (): void {
    $result = qtRun(qtInput(['a' => ['100.00'], 'b' => ['100.00'], 'c' => ['100.00']], ['order:100.00']));

    $shares = array_map(static fn ($line) => $line->adjustments[0]->amount, $result->lines);

    expect(qtDecimals($shares))->toBe(['-33.34', '-33.33', '-33.33'])
        ->and(array_map(static fn ($line) => [$line->adjustments[0]->target, $line->adjustments[0]->lineId, $line->adjustments[0]->promotionId], $result->lines))
        ->toBe([[AdjustmentTarget::Order, 'a', 'promo-1'], [AdjustmentTarget::Order, 'b', 'promo-1'], [AdjustmentTarget::Order, 'c', 'promo-1']])
        ->and(qtDecimals(array_map(static fn ($line) => $line->total, $result->lines)))->toBe(['66.66', '66.67', '66.67'])
        ->and(qtDecimals([$result->totals->subtotal, $result->totals->discountTotal, $result->total]))->toBe(['300.00', '100.00', '200.00'])
        ->and($result->orderAdjustments)->toHaveCount(1)
        ->and($result->orderAdjustments[0]->lineId)->toBeNull();
});

it('weights the allocation by line subtotals after line adjustments', function (): void {
    $result = qtRun(qtInput(['1' => ['100.00'], '2' => ['300.00'], '3' => ['0.00']], ['line:2:200.00', 'order:50.00']));

    expect(array_map(static fn ($line) => qtDecimals(array_map(static fn ($a) => $a->amount, $line->adjustments)), $result->lines))
        ->toBe([['-25.00'], ['-200.00', '-25.00'], []])
        ->and($result->lines[1]->adjustments[0]->target)->toBe(AdjustmentTarget::Line)
        ->and(qtDecimals([$result->totals->discountTotal, $result->total]))->toBe(['250.00', '150.00']);
});

it('adds delivery after its discounts and excluded taxes to the total', function (): void {
    $result = qtRun(qtInput(['a' => ['100.00']], ['ship:300.00', 'shipoff:50.00', 'tax:a:20.00']));

    expect(qtDecimals([$result->totals->subtotal, $result->totals->deliveryTotal, $result->totals->taxTotal, $result->total]))
        ->toBe(['100.00', '250.00', '20.00', '370.00'])
        ->and($result->delivery?->methodCode)->toBe('courier')
        ->and($result->deliveryAdjustments)->toHaveCount(1)
        ->and($result->lines[0]->taxLines)->toHaveCount(1)
        ->and($result->lines[0]->total->toDecimal())->toBe('120.00');
});

it('quotes an empty input as zero', function (): void {
    $result = qtRun(qtInput([]));

    expect($result->lines)->toBe([])->and($result->total->toDecimal())->toBe('0.00');
});

it('fails a quote whose total would be negative', function (): void {
    expect(fn () => qtRun(qtInput(['a' => ['10.00']], ['order:20.00'])))
        ->toThrow(fn (QuoteFailed $e) => expect([$e->code(), $e->stage, $e->reason])->toBe(['quotes.failed', 'totals', 'negative_total']));
});

it('fails a line discounted below zero', function (): void {
    expect(fn () => qtRun(qtInput(['a' => ['10.00']], ['line:a:10.01'])))
        ->toThrow(fn (QuoteFailed $e) => expect($e->reason)->toBe('negative_line'));
});

it('fails delivery discounts without a delivery charge', function (): void {
    expect(fn () => qtRun(qtInput(['a' => ['10.00']], ['shipoff:1.00'])))
        ->toThrow(fn (QuoteFailed $e) => expect($e->reason)->toBe('delivery_without_charge'));
});

it('wraps a stage exception into QuoteFailed', function (): void {
    expect(fn () => qtRun(qtInput(['a' => ['10.00']], ['boom'])))
        ->toThrow(function (QuoteFailed $e): void {
            expect([$e->stage, $e->reason, $e->context()['cause']])->toBe(['test.codes', 'stage_error', RuntimeException::class])
                ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
        });
});

it('fails when no price is found, naming the cause', function (): void {
    $input = new QuoteInput(qtInput([])->context, [new QuoteLineInput('a', FakeSellables::snapshot('free'), 1)]);

    expect(fn () => qtRun($input))
        ->toThrow(fn (QuoteFailed $e) => expect([$e->stage, $e->context()['cause']])->toBe(['base', 'prices.price_not_found']));
});

it('fails when a stage after totals changes amounts', function (): void {
    expect(fn () => qtRun(qtInput(['a' => ['10.00']], ['late'])))
        ->toThrow(fn (QuoteFailed $e) => expect($e->reason)->toBe('totals_missing'));
});

it('uses only prices in the quote currency', function (): void {
    $input = new QuoteInput(qtInput([])->context, [new QuoteLineInput('a', FakeSellables::snapshot('usd', Money::of('1.00', 'USD')), 1)]);

    expect(fn () => qtRun($input))
        ->toThrow(fn (QuoteFailed $e) => expect($e->context()['cause'])->toBe('prices.price_not_found'));

    Commerce::fake()->prices->set(SellableRef::of('fake', 'usd'), Money::of('1.00', 'RUB'));
    expect(qtRun($input)->total->toDecimal())->toBe('1.00');
});

it('is reachable from the Commerz root', function (): void {
    expect(Commerz::quotes())->toBe(app(QuotePipeline::class))
        ->and(Commerz::quotes()->run(qtInput(['a' => ['1.00']]))->total->toDecimal())->toBe('1.00');
});
