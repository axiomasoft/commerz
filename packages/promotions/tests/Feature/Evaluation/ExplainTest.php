<?php

declare(strict_types=1);

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Promotions\Definitions\Data\ExplainPromotions;
use Commerz\Promotions\Definitions\Data\PromotionVerdict;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Definitions\Enums\VerdictKind;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, EvEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
});

it('explains an exclusive promotion that stops the rest (G22)', function (): void {
    $exclusive = evPromotion('e', PromotionStage::Cart, [evLinePercent(1000)], ['priority' => 300, 'stacking' => Stacking::Exclusive]);
    evPromotion('s', PromotionStage::Cart, [evLinePercent(500)], ['priority' => 100]);

    $explanation = Promotions::promotions()->explain(new ExplainPromotions(evInput(['1000.00'])));

    expect($explanation->failure)->toBeNull()
        ->and($explanation->quote?->total->toDecimal())->toBe('900.00')
        ->and($explanation->verdicts[0])->toEqual(new PromotionVerdict($exclusive->id, 'e', 'E', PromotionStage::Cart, null, VerdictKind::Applied, null, Money::of('100.00', 'RUB')))
        ->and(array_map(static fn (PromotionVerdict $v): string => "{$v->code}|{$v->kind->value}|{$v->reason}", $explanation->verdicts))
        ->toBe(['e|applied|', 's|skipped|stopped_by_promotion'])
        ->and($explanation->codeRejections)->toBe([]);
});

it('gives the verdicts of every stage in order, with the code rejections, and leaves out coupon promotions without a code', function (): void {
    evPromotion('catalog', PromotionStage::Catalog, [evLinePercent(1000)]);
    $coupon = cpnPromotion('autumn', ['conditions' => [pdfMinimum('3000.00')]]);
    cpnCoupon($coupon, 'AUTUMN');
    cpnPromotion('silent');

    $explanation = Promotions::promotions()->explain(new ExplainPromotions(evInput(['1000.00'], ['autumn', 'NOPE'])));

    expect(array_map(static fn (PromotionVerdict $v): string => "{$v->code}|{$v->stage->value}|{$v->couponCode}|{$v->kind->value}|".($v->reason ?? $v->discount?->toDecimal()), $explanation->verdicts))
        ->toBe(['catalog|catalog||applied|100.00', 'autumn|cart|AUTUMN|rejected|minimum_not_reached'])
        ->and(array_map(static fn ($r): string => "{$r->reason}|{$r->code}", $explanation->codeRejections))->toBe(['unknown_code|NOPE'])
        ->and(evRejections($explanation->quote))->toBe(['promotions.catalog|unknown_code|NOPE', 'promotions.cart|minimum_not_reached|autumn']);
});

it('reports a failed quote with the verdicts given before it failed', function (): void {
    evPromotion('catalog', PromotionStage::Catalog, [evLinePercent(1000)]);
    evPromotion('broken', PromotionStage::Cart, [new RuleSpec('test.rogue_effect', ['mode' => 'throws'])]);

    $explanation = Promotions::promotions()->explain(new ExplainPromotions(evInput(['1000.00'])));

    expect($explanation->quote)->toBeNull()
        ->and($explanation->failure)->toBe('quotes.failed:promotions.cart:stage_error')
        ->and(array_map(static fn (PromotionVerdict $v): string => "{$v->code}|{$v->kind->value}", $explanation->verdicts))->toBe(['catalog|applied']);
});

it('explains nothing of an earlier run when the quote fails before the promotions', function (): void {
    evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]);
    $ref = SellableRef::of('fake', 'unpriced');
    $this->commerce->prices->set($ref, Money::of('100.00', 'RUB'));
    $input = new QuoteInput(evContext(), [new QuoteLineInput('1', new SellableSnapshot($ref, 'SKU', 'Item', SellableKind::Physical, true), 1)]);

    expect(Promotions::promotions()->explain(new ExplainPromotions($input))->verdicts)->toHaveCount(1);

    Commerce::fake(); // no price any more: the base stage fails

    $explanation = Promotions::promotions()->explain(new ExplainPromotions($input));

    expect($explanation->failure)->toBe('quotes.failed:base:stage_error')
        ->and($explanation->verdicts)->toBe([])
        ->and($explanation->codeRejections)->toBe([]);
});

it('writes nothing', function (): void {
    $coupon = cpnPromotion('autumn');
    cpnCoupon($coupon, 'AUTUMN');

    $queries = pdfQueries(fn () => Promotions::promotions()->explain(new ExplainPromotions(evInput(['1000.00'], ['AUTUMN']))));

    expect(array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1))->toBe([]);
});
