<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Contracts\CartTransformRule;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\ExpandedComponent;
use Commerz\Quotes\Data\LineExpand;
use Commerz\Quotes\Data\LinesMerge;
use Commerz\Quotes\Data\LineUpdate;
use Commerz\Quotes\Data\MergeTargetLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class CtBundleExpandRule implements CartTransformRule
{
    public function key(): string
    {
        return 'test.bundle-expand';
    }

    public function transform(QuoteInput $input): array
    {
        $operations = [];
        $currency = $input->context->currency;

        foreach ($input->lines as $line) {
            if ((string) $line->sellable->ref === 'fake:starter-bundle') {
                $operations[] = new LineExpand($line->lineId, [
                    new ExpandedComponent(
                        sellable: SellableRef::of('fake', 'camera-body'),
                        quantityPerUnit: 1,
                        fixedUnitPrice: Money::of('800.00', $currency),
                    ),
                    new ExpandedComponent(
                        sellable: SellableRef::of('fake', 'lens-50mm'),
                        quantityPerUnit: 2,
                        fixedUnitPrice: Money::of('150.00', $currency),
                    ),
                ]);
            }
        }

        return $operations;
    }
}

final readonly class CtLinesMergeRule implements CartTransformRule
{
    public function key(): string
    {
        return 'test.lines-merge';
    }

    public function transform(QuoteInput $input): array
    {
        $cameraLine = null;
        $tripodLine = null;

        foreach ($input->lines as $line) {
            if ((string) $line->sellable->ref === 'fake:camera-body') {
                $cameraLine = $line;
            } elseif ((string) $line->sellable->ref === 'fake:tripod') {
                $tripodLine = $line;
            }
        }

        if ($cameraLine === null || $tripodLine === null) {
            return [];
        }

        $pairs = min($cameraLine->quantity, $tripodLine->quantity);

        return [
            new LinesMerge(
                targets: [
                    new MergeTargetLine($cameraLine->lineId, $pairs),
                    new MergeTargetLine($tripodLine->lineId, $pairs),
                ],
                parentSellable: SellableRef::of('fake', 'creator-combo'),
                quantity: $pairs,
                fixedUnitPrice: Money::of('950.00', $input->context->currency),
            ),
        ];
    }
}

final readonly class CtLineUpdateRule implements CartTransformRule
{
    public function key(): string
    {
        return 'test.line-update';
    }

    public function transform(QuoteInput $input): array
    {
        if (! in_array('ENGRAVE', $input->codes, true)) {
            return [];
        }

        $first = $input->lines[0] ?? null;

        if ($first === null) {
            return [];
        }

        return [
            new LineUpdate(
                lineId: $first->lineId,
                fixedUnitPrice: $first->sellable->basePrice?->plus(Money::of('25.00', $input->context->currency)),
                title: $first->sellable->name.' (Engraved)',
            ),
        ];
    }
}

final readonly class CtBlockingFailureRule implements CartTransformRule
{
    public function key(): string
    {
        return 'test.blocking-boom';
    }

    public function transform(QuoteInput $input): array
    {
        throw new RuntimeException('transform engine exploded');
    }
}

final readonly class CtOptionalFailureRule implements CartTransformRule, QuoteStagePolicy
{
    public function key(): string
    {
        return 'test.optional-boom';
    }

    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::IgnoreOnFailure;
    }

    public function timeoutMilliseconds(): ?int
    {
        return null;
    }

    public function transform(QuoteInput $input): array
    {
        throw new RuntimeException('non-critical transform skipped');
    }
}

final readonly class CtConflictingRule implements CartTransformRule
{
    public function key(): string
    {
        return 'test.conflicting';
    }

    public function transform(QuoteInput $input): array
    {
        $lineId = $input->lines[0]->lineId;

        return [
            new LineUpdate($lineId, title: 'Updated A'),
            new LineUpdate($lineId, title: 'Updated B'),
        ];
    }
}

final class CtRecordingParcelStage implements QuoteStage
{
    public static ?int $recordedWeightGrams = null;

    public function key(): string
    {
        return 'test.parcel-recorder';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Delivery;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $selection = $state->input->delivery;

        if ($selection === null) {
            return $state;
        }

        self::$recordedWeightGrams = $selection->parcel->weightGrams;

        return $state->withDelivery(new DeliveryCharge('courier', 'Courier', 'manual', Money::of('50.00', $state->currency())));
    }
}

final class CtTransformPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.cart-transform', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(CartTransformRule::KIND, new CtBundleExpandRule);
        $registrar->contribute(CartTransformRule::KIND, new CtLinesMergeRule);
        $registrar->contribute(CartTransformRule::KIND, new CtLineUpdateRule);
        $registrar->contribute(QuoteStage::KIND, new CtRecordingParcelStage);
    }
}

final class CtBlockingPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.cart-transform-block', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(CartTransformRule::KIND, new CtBlockingFailureRule);
    }
}

final class CtOptionalPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.cart-transform-optional', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(CartTransformRule::KIND, new CtOptionalFailureRule);
    }
}

final class CtConflictPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.cart-transform-conflict', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(CartTransformRule::KIND, new CtConflictingRule);
    }
}

function ctQuoteContext(): CommerceContext
{
    return CommerceContext::for(
        ChannelId::fromString('0190a000-0000-7000-8000-000000000001'),
        Currency::of('RUB'),
        'ru',
        new DateTimeImmutable('2026-11-26T12:00:00Z'),
    );
}

function ctWeightedSnapshot(string $ref, string $price, int $weightGrams, SellableKind $kind = SellableKind::Physical): SellableSnapshot
{
    $base = FakeSellables::snapshot($ref, Money::of($price, 'RUB'), $kind);

    return new SellableSnapshot(
        ref: $base->ref,
        sku: $base->sku,
        name: $base->name,
        kind: $kind,
        requiresShipping: $kind === SellableKind::Physical,
        basePrice: $base->basePrice,
        weightGrams: $weightGrams,
    );
}

beforeEach(function (): void {
    CtRecordingParcelStage::$recordedWeightGrams = null;
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        ctWeightedSnapshot('fake:starter-bundle', '1100.00', 0, SellableKind::Service),
        ctWeightedSnapshot('fake:camera-body', '900.00', 650),
        ctWeightedSnapshot('fake:lens-50mm', '200.00', 300),
        ctWeightedSnapshot('fake:tripod', '250.00', 1200),
        ctWeightedSnapshot('fake:creator-combo', '950.00', 1850),
    );
});

it('places CartTransform at stage 01 before Base', function (): void {
    expect(QuoteAnchor::CartTransform->order())->toBe(0)
        ->and(QuoteAnchor::Base->order())->toBe(1);
});

it('expands a bundle line into deterministic component lines and updates parcel shipping weight', function (): void {
    config(['commerz.plugins' => [CtTransformPlugin::class]]);

    $bundle = app(Sellables::class)->resolve(SellableRef::of('fake', 'starter-bundle'), ctQuoteContext());
    $address = new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Ivan', '+70000000000');
    $delivery = new DeliverySelection('courier', $address, new Parcel(0, Money::zero('RUB')));

    $input = new QuoteInput(
        ctQuoteContext(),
        [new QuoteLineInput('0190a000-0000-7000-8000-000000000010', $bundle, 2)],
        delivery: $delivery,
    );

    $first = app(QuotePipeline::class)->run($input);
    $second = app(QuotePipeline::class)->run($input);

    // 2 × (1 × 800 + 2 × 150) = 2200.00 + 50.00 shipping = 2250.00
    // Weight: 2 × 650g + 4 × 300g = 2500g
    expect($first)->toEqual($second)
        ->and($first->lines)->toHaveCount(2)
        ->and((string) $first->lines[0]->sellable->ref)->toBe('fake:camera-body')
        ->and($first->lines[0]->quantity)->toBe(2)
        ->and($first->lines[0]->subtotal->toDecimal())->toBe('1600.00')
        ->and($first->lines[0]->lineId)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/')
        ->and((string) $first->lines[1]->sellable->ref)->toBe('fake:lens-50mm')
        ->and($first->lines[1]->quantity)->toBe(4)
        ->and($first->lines[1]->subtotal->toDecimal())->toBe('600.00')
        ->and($first->totals->subtotal->toDecimal())->toBe('2200.00')
        ->and($first->total->toDecimal())->toBe('2250.00')
        ->and(CtRecordingParcelStage::$recordedWeightGrams)->toBe(2500);
});

it('merges matching component lines into a bundle and preserves unmerged remainder quantities', function (): void {
    config(['commerz.plugins' => [CtTransformPlugin::class]]);

    $camera = app(Sellables::class)->resolve(SellableRef::of('fake', 'camera-body'), ctQuoteContext());
    $tripod = app(Sellables::class)->resolve(SellableRef::of('fake', 'tripod'), ctQuoteContext());

    // 2 cameras + 1 tripod -> 1 creator-combo (950.00) + 1 remaining camera (900.00) = 1850.00
    $input = new QuoteInput(ctQuoteContext(), [
        new QuoteLineInput('line-cam', $camera, 2),
        new QuoteLineInput('line-tri', $tripod, 1),
    ]);

    $result = app(QuotePipeline::class)->run($input);

    expect($result->lines)->toHaveCount(2)
        ->and((string) $result->lines[0]->sellable->ref)->toBe('fake:creator-combo')
        ->and($result->lines[0]->quantity)->toBe(1)
        ->and($result->lines[0]->unitPrice->toDecimal())->toBe('950.00')
        ->and($result->lines[1]->lineId)->toBe('line-cam')
        ->and((string) $result->lines[1]->sellable->ref)->toBe('fake:camera-body')
        ->and($result->lines[1]->quantity)->toBe(1)
        ->and($result->lines[1]->unitPrice->toDecimal())->toBe('900.00')
        ->and($result->total->toDecimal())->toBe('1850.00');
});

it('updates an existing line title and unit price via LineUpdate', function (): void {
    config(['commerz.plugins' => [CtTransformPlugin::class]]);

    $lens = app(Sellables::class)->resolve(SellableRef::of('fake', 'lens-50mm'), ctQuoteContext());
    $input = new QuoteInput(ctQuoteContext(), [new QuoteLineInput('line-lens', $lens, 2)], ['ENGRAVE']);

    $result = app(QuotePipeline::class)->run($input);

    expect($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->sellable->name)->toContain('(Engraved)')
        ->and($result->lines[0]->unitPrice->toDecimal())->toBe('225.00')
        ->and($result->total->toDecimal())->toBe('450.00');
});

it('enforces FailurePolicy::BlockOnFailure by default when a transform rule throws or conflicts', function (): void {
    $lens = app(Sellables::class)->resolve(SellableRef::of('fake', 'lens-50mm'), ctQuoteContext());
    $input = new QuoteInput(ctQuoteContext(), [new QuoteLineInput('line-1', $lens, 1)]);

    config(['commerz.plugins' => [CtBlockingPlugin::class]]);
    expect(fn () => app(QuotePipeline::class)->run($input))
        ->toThrow(fn (QuoteFailed $e) => expect([$e->stage, $e->reason])->toBe(['test.blocking-boom', 'stage_error']));
});

it('blocks conflicting transforms targeting the same line', function (): void {
    config(['commerz.plugins' => [CtConflictPlugin::class]]);

    $lens = app(Sellables::class)->resolve(SellableRef::of('fake', 'lens-50mm'), ctQuoteContext());
    $input = new QuoteInput(ctQuoteContext(), [new QuoteLineInput('line-1', $lens, 1)]);

    expect(fn () => app(QuotePipeline::class)->run($input))
        ->toThrow(fn (QuoteFailed $e) => expect([$e->stage, $e->reason])->toBe(['test.conflicting', 'conflicting_transform']));
});

it('records a rejection without crashing when a CartTransformRule opts into FailurePolicy::IgnoreOnFailure', function (): void {
    config(['commerz.plugins' => [CtOptionalPlugin::class]]);

    $lens = app(Sellables::class)->resolve(SellableRef::of('fake', 'lens-50mm'), ctQuoteContext());
    $input = new QuoteInput(ctQuoteContext(), [new QuoteLineInput('line-1', $lens, 1)]);

    $result = app(QuotePipeline::class)->run($input);

    expect($result->total->toDecimal())->toBe('200.00')
        ->and($result->rejections)->toHaveCount(1)
        ->and($result->rejections[0]->source)->toBe('test.optional-boom');
});
