<?php

declare(strict_types=1);

// Shared fixtures of the promotion engine tests: quotes run through the real pipeline with fake prices.

use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Data\ExplainPromotions;
use Commerz\Promotions\Definitions\Data\PromotionVerdict;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Tests\Fixtures\EvaluationPlugin;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliverySelection;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\Rejection;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Coupons/Helpers.php';

/** The engine tests' plugin goes in before the registry is compiled (the migrations already resolve it). */
trait EvEnvironment
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [EvaluationPlugin::class]);
    }
}

function evContext(?string $customer = null): CommerceContext
{
    $context = CommerceContext::for(pdfChannel(), Currency::of('RUB'), 'ru', new DateTimeImmutable('2026-10-09T12:00:00Z'));

    return $customer === null ? $context : $context->withCustomer(CustomerRef::fromString($customer));
}

/**
 * @param  array<array-key, string|array<array-key, mixed>>  $lines  a line is `'<qty>x<unit>'` or `'<unit>'`, or a list
 *                                                                   whose first item is that and `categories` its
 *                                                                   category codes; a list gets ids '1', '2', …, string
 *                                                                   keys are the ids
 * @param  list<string>  $codes
 * @param  string|null  $delivery  price of a flat delivery, e.g. '300.00'
 */
function evInput(array $lines, array $codes = [], ?CommerceContext $context = null, ?string $delivery = null): QuoteInput
{
    $inputs = [];

    foreach ($lines as $key => $spec) {
        $id = is_int($key) ? (string) ($key + 1) : $key;
        $spec = is_array($spec) ? $spec : [$spec];
        $amount = $spec[0];
        assert(is_string($amount));
        [$quantity, $unit] = str_contains($amount, 'x') ? explode('x', $amount, 2) : ['1', $amount];
        $categories = $spec['categories'] ?? [];
        assert(is_array($categories));

        $inputs[] = new QuoteLineInput($id, new SellableSnapshot(
            SellableRef::of('fake', 'item-'.strtolower($id)),
            'SKU-'.$id,
            'Item '.$id,
            SellableKind::Physical,
            true,
            basePrice: Money::of($unit, 'RUB'),
            categories: array_values(array_map(strval(...), $categories)),
        ), (int) $quantity);
    }

    $selection = $delivery === null ? null : new DeliverySelection('flat:'.$delivery, new Address('RU'), new Parcel(1000, Money::of('0.00', 'RUB')));

    return new QuoteInput($context ?? evContext(), $inputs, $codes, $selection);
}

function evRun(QuoteInput $input): QuoteResult
{
    return app(QuotePipeline::class)->run($input);
}

/**
 * @param  list<RuleSpec>  $effects
 * @param  array<string, mixed>  $with  other named arguments of CreatePromotion
 */
function evPromotion(string $code, PromotionStage $stage, array $effects, array $with = []): PromotionView
{
    return pdfPromotion($code, ['stage' => $stage, 'effects' => $effects] + $with);
}

/**
 * Creates the promotions in the given order, or in reverse: the result must not depend on it (PRM-04).
 *
 * @param  list<Closure(): PromotionView>  $makers
 */
function evCreate(array $makers, bool $reverse): void
{
    foreach ($reverse ? array_reverse($makers) : $makers as $make) {
        $make();
    }
}

function evLinePercent(int $rateBp): RuleSpec
{
    return new RuleSpec('line.percent_off', ['rateBp' => $rateBp]);
}

function evLineFixed(string $amount, string $per = 'unit'): RuleSpec
{
    return new RuleSpec('line.fixed_off', ['amount' => Money::of($amount, 'RUB'), 'per' => $per]);
}

function evOrderFixed(string $amount): RuleSpec
{
    return new RuleSpec('order.fixed_off', ['amount' => Money::of($amount, 'RUB')]);
}

function evDeliveryPercent(int $rateBp): RuleSpec
{
    return new RuleSpec('delivery.percent_off', ['rateBp' => $rateBp]);
}

/** @param  array<string, mixed>  $params */
function evBuyXGetY(array $params = []): RuleSpec
{
    return new RuleSpec('buy_x_get_y', $params + ['buy' => 2, 'get' => 1]);
}

/**
 * The declared discounts of a result: own line adjustments (lines in order), then order and delivery adjustments,
 * as `<target>[:<line>] <amount> <promotion code>[ <coupon>]`.
 *
 * @return list<string>
 */
function evDiscounts(QuoteResult $result): array
{
    $codes = DB::table('cmz_promotions')->pluck('code', 'id')->all();
    $describe = static function (Adjustment $a) use ($codes): string {
        $target = $a->target === AdjustmentTarget::Line ? "line:{$a->lineId}" : $a->target->value;
        $promotion = $a->promotionId === null ? $a->source : $codes[$a->promotionId] ?? '?';

        return trim("{$target} {$a->amount->toDecimal()} {$promotion} {$a->couponCode}");
    };

    $declared = [];

    foreach ($result->lines as $line) {
        foreach ($line->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Line) {
                $declared[] = $describe($adjustment);
            }
        }
    }

    foreach ([...$result->orderAdjustments, ...$result->deliveryAdjustments] as $adjustment) {
        $declared[] = $describe($adjustment);
    }

    return $declared;
}

/** @return list<string> `<source>|<reason>|<code>` of each rejection */
function evRejections(QuoteResult $result): array
{
    return array_map(static fn (Rejection $r): string => "{$r->source}|{$r->reason}|{$r->code}", $result->rejections);
}

/** @return list<string> `<code>|<kind>|<reason or discount>` of each verdict */
function evVerdicts(QuoteInput $input): array
{
    return array_map(
        static fn (PromotionVerdict $v): string => "{$v->code}|{$v->kind->value}|".($v->reason ?? $v->discount?->toDecimal()),
        Promotions::promotions()->explain(new ExplainPromotions($input))->verdicts,
    );
}

/** The failure of a quote that cannot be computed, as `<stage>|<reason>|<cause>`. */
function evFailure(QuoteInput $input): string
{
    try {
        evRun($input);
    } catch (QuoteFailed $e) {
        $cause = $e->context()['cause'] ?? null;

        return "{$e->stage}|{$e->reason}|".(is_string($cause) ? $cause : '');
    }

    return 'no failure';
}

function evRounding(string $mode): void
{
    config()->set('commerz-promotions.rounding', $mode);
    app()->forgetInstance(PromotionsOptions::class);
}

/** @param  array<string, mixed>  $columns */
function evSetColumns(PromotionView $promotion, array $columns): void
{
    DB::table('cmz_promotions')->where('id', $promotion->id->value)->update($columns);
}
