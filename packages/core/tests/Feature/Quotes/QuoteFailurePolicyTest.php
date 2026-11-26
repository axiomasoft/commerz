<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FakeSellables;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class ResilientTestStage implements QuoteStage, QuoteStagePolicy
{
    public function __construct(
        private string $stageKey,
        private FailurePolicy $policy,
        private ?int $timeoutMs = null,
        private bool $shouldThrow = false,
        private int $sleepMs = 0,
    ) {}

    public function key(): string
    {
        return $this->stageKey;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CartPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function failurePolicy(): FailurePolicy
    {
        return $this->policy;
    }

    public function timeoutMilliseconds(): ?int
    {
        return $this->timeoutMs;
    }

    public function apply(QuoteState $state): QuoteState
    {
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        if ($this->shouldThrow) {
            throw new RuntimeException("boom from {$this->stageKey}");
        }

        return $state->withAdjustment(
            Adjustment::order(Money::of('1.00', $state->currency())->negate(), $this->stageKey, 'applied'),
        );
    }
}

final class IgnoreFailurePlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.ignore-failure', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new ResilientTestStage(
            stageKey: 'test.optional-boom',
            policy: FailurePolicy::IgnoreOnFailure,
            shouldThrow: true,
        ));
    }
}

final class BlockTimeoutPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.block-timeout', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new ResilientTestStage(
            stageKey: 'test.slow-blocking',
            policy: FailurePolicy::BlockOnFailure,
            timeoutMs: 10,
            sleepMs: 30,
        ));
    }
}

final class IgnoreTimeoutPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.ignore-timeout', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new ResilientTestStage(
            stageKey: 'test.slow-optional',
            policy: FailurePolicy::IgnoreOnFailure,
            timeoutMs: 10,
            sleepMs: 30,
        ));
    }
}

final class InvalidTimeoutPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.invalid-timeout', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new ResilientTestStage(
            stageKey: 'test.zero-timeout',
            policy: FailurePolicy::BlockOnFailure,
            timeoutMs: 0,
        ));
    }
}

function fpInput(): QuoteInput
{
    $context = CommerceContext::for(
        ChannelId::fromString('0190a000-0000-7000-8000-000000000001'),
        Currency::of('RUB'),
        'ru',
        new DateTimeImmutable('2026-11-26T12:00:00Z'),
    );

    return new QuoteInput($context, [
        new QuoteLineInput('a', FakeSellables::snapshot('item-a', Money::of('10.00', 'RUB')), 1),
    ]);
}

beforeEach(function (): void {
    Commerce::fake();
});

it('does not crash QuotePipeline when a stage with FailurePolicy::IgnoreOnFailure throws', function (): void {
    config(['commerz.plugins' => [IgnoreFailurePlugin::class]]);

    $result = app(QuotePipeline::class)->run(fpInput());

    expect($result->total->toDecimal())->toBe('10.00')
        ->and($result->rejections)->toHaveCount(1)
        ->and($result->rejections[0])->toEqual(new Rejection('test.optional-boom', 'stage_error', 'boom from test.optional-boom'));
});

it('fails QuotePipeline when a blocking stage exceeds timeoutMilliseconds()', function (): void {
    config(['commerz.plugins' => [BlockTimeoutPlugin::class]]);

    expect(fn () => app(QuotePipeline::class)->run(fpInput()))
        ->toThrow(fn (QuoteFailed $e) => expect([$e->stage, $e->reason])->toBe(['test.slow-blocking', 'stage_timeout']));
});

it('skips a timed-out stage without crashing when FailurePolicy::IgnoreOnFailure is set', function (): void {
    config(['commerz.plugins' => [IgnoreTimeoutPlugin::class]]);

    $result = app(QuotePipeline::class)->run(fpInput());

    expect($result->total->toDecimal())->toBe('10.00')
        ->and($result->orderAdjustments)->toBe([])
        ->and($result->rejections)->toHaveCount(1)
        ->and($result->rejections[0]->source)->toBe('test.slow-optional')
        ->and($result->rejections[0]->reason)->toBe('stage_timeout');
});

it('rejects non-positive timeoutMilliseconds() as invalid configuration', function (): void {
    config(['commerz.plugins' => [InvalidTimeoutPlugin::class]]);

    expect(fn () => app(QuotePipeline::class)->run(fpInput()))
        ->toThrow(InvalidConfiguration::class, 'timeoutMilliseconds() must be a positive integer or null');
});
