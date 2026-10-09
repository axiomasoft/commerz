<?php

declare(strict_types=1);

// A rule or an activator is a plugin: its answer is checked, and what cannot be trusted fails the quote (PRM-05).

use Commerz\Money\Money;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, EvEnvironment::class);

beforeEach(function (): void {
    Commerce::fake();
});

function evRogueEffect(string $mode): RuleSpec
{
    return new RuleSpec('test.rogue_effect', ['mode' => $mode]);
}

it('fails the quote when a line filter keeps a line it was not given', function (string $mode): void {
    evPromotion('rogue', PromotionStage::Cart, [evLinePercent(1000)], ['conditions' => [new RuleSpec('test.rogue_filter', ['mode' => $mode])]]);

    expect(evFailure(evInput(['100.00'])))->toBe('promotions.cart|stage_error|promotions.invalid_rule_result');
})->with(['a foreign line' => ['foreign'], 'no list at all' => ['no_list']]);

it('fails the quote when an effect answers what cannot be trusted', function (string $mode, PromotionStage $stage): void {
    evPromotion('rogue', $stage, [evRogueEffect($mode)], ['conditions' => [new RuleSpec('line.sellable_in', ['sellables' => ['fake:item-1']])]]);

    expect(evFailure(evInput(['100.00', '200.00'])))->toBe("{$stage->stageKey()}|stage_error|promotions.invalid_rule_result");
})->with([
    'a line outside the eligible ones' => ['every_line', PromotionStage::Cart],
    'another currency' => ['foreign_currency', PromotionStage::Cart],
    'an order discount in the catalog stage' => ['order', PromotionStage::Catalog],
    'something that is no discount' => ['not_a_discount', PromotionStage::Cart],
]);

it('cannot even build a negative discount', function (): void {
    evPromotion('rogue', PromotionStage::Cart, [evRogueEffect('negative')]);

    expect(evFailure(evInput(['100.00'])))->toBe('promotions.cart|stage_error|promotions.invalid_discount');
});

it('does not swallow the exception of a rule', function (): void {
    evPromotion('rogue', PromotionStage::Cart, [evRogueEffect('throws')]);

    expect(evFailure(evInput(['100.00'])))->toBe('promotions.cart|stage_error|RuntimeException');
});

it('fails the quote when an activator switches on what it was not asked about', function (string $code): void {
    pdfPromotion('rogue', ['activator' => 'test.rogue']);

    expect(evFailure(evInput(['100.00'], [$code])))->toBe('promotions.catalog|stage_error|promotions.invalid_rule_result');
})->with([
    'a promotion that is not its candidate' => ['FOREIGN'],
    'a promotion twice' => ['TWICE'],
    'by a code that was not presented' => ['GHOST'],
]);

it('applies what a third-party activator switches on properly', function (): void {
    pdfPromotion('rogue', ['activator' => 'test.rogue']);

    expect(evDiscounts(evRun(evInput(['100.00'], ['RIGHT']))))->toBe(['order -10.00 rogue RIGHT']);
});

it('rejects a promotion whose activator no plugin contributes', function (): void {
    $promotion = pdfPromotion('orphan');
    evSetColumns($promotion, ['activator' => 'acme.gone']);
    $input = evInput(['100.00']);

    expect(evDiscounts(evRun($input)))->toBe([])
        ->and(evVerdicts($input))->toBe(['orphan|rejected|unsupported_rule']);
});

it('leaves nothing of a promotion the budget refuses, not even its earlier effects', function (string $budget, array $discounts): void {
    evPromotion('pair', PromotionStage::Cart, [evLinePercent(1000), evOrderFixed('50.00')], ['budget' => Money::of($budget, 'RUB')]);

    expect(evDiscounts(evRun(evInput(['1000.00']))))->toBe($discounts);
})->with([
    'over the budget' => ['120.00', []],
    'within the budget' => ['150.00', ['line:1 -100.00 pair', 'order -50.00 pair']],
]);

it('clamps a discount to what is left of the line', function (): void {
    evPromotion('first', PromotionStage::Cart, [evLinePercent(9000)], ['priority' => 10]);
    evPromotion('second', PromotionStage::Cart, [evLineFixed('50.00', 'line')]);

    $result = evRun(evInput(['100.00']));

    expect(evDiscounts($result))->toBe(['line:1 -90.00 first', 'line:1 -10.00 second'])
        ->and($result->total->toDecimal())->toBe('0.00');
});

it('rejects a promotion that gives nothing on what is left', function (): void {
    evPromotion('first', PromotionStage::Cart, [evLinePercent(10000)], ['priority' => 10]);
    evPromotion('second', PromotionStage::Cart, [evLineFixed('50.00', 'line')]);
    $input = evInput(['100.00']);

    expect(evVerdicts($input))->toBe(['first|applied|100.00', 'second|rejected|no_discount']);
});

it('clamps line discounts after an order discount to the remaining items total', function (array $amounts, array $expected): void {
    evPromotion('order', PromotionStage::Cart, [evOrderFixed('90.00')], ['priority' => 10]);
    evPromotion('line', PromotionStage::Cart, [evLineFixed('100.00', 'line')]);

    $result = evRun(evInput($amounts));

    expect(evDiscounts($result))->toBe($expected)
        ->and($result->total->toDecimal())->toBe('0.00');
})->with([
    'one line' => [['100.00'], ['line:1 -10.00 line', 'order -90.00 order']],
    'two lines' => [['100.00', '100.00'], ['line:1 -100.00 line', 'line:2 -10.00 line', 'order -90.00 order']],
]);

it('rejects invalid stored rule parameters without failing the quote and reports them in reconcile', function (array $config): void {
    $promotion = evPromotion('broken', PromotionStage::Cart, [evLinePercent(1000)]);
    evPromotion('healthy', PromotionStage::Cart, [evLinePercent(500)]);
    DB::table('cmz_promotion_rules')->where('promotion_id', $promotion->id->value)->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
    $input = evInput(['100.00']);

    expect(evDiscounts(evRun($input)))->toBe(['line:1 -5.00 healthy'])
        ->and(evVerdicts($input))->toBe(['broken|rejected|unsupported_rule', 'healthy|applied|5.00']);
    $this->artisan('commerz:promotions:reconcile')->expectsOutputToContain('unsupported_rule')->assertExitCode(1);
})->with([
    'missing rate' => [[]],
    'out of range rate' => [['rateBp' => 10001]],
    'unknown parameter' => [['rateBp' => 1000, 'extra' => 1]],
]);

it('rejects an over-budget discount without overflowing the money range', function (): void {
    $promotion = evPromotion('huge', PromotionStage::Cart, [evLineFixed('1.00', 'line')], ['budget' => Money::ofMinor(PHP_INT_MAX, 'RUB')]);
    evSetColumns($promotion, ['spent_minor' => PHP_INT_MAX - 50]);

    expect(evVerdicts(evInput(['100.00'])))->toBe(['huge|rejected|budget_exhausted']);
});
