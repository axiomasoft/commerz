<?php

declare(strict_types=1);

use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Evaluation\Internal\PromotionSessions;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class, EvEnvironment::class);

beforeEach(function (): void {
    Commerce::fake();
});

/** @return int sessions the map holds */
function evSessionCount(): int
{
    $sessions = new ReflectionProperty(PromotionSessions::class, 'sessions')->getValue(app(PromotionSessions::class));

    return count($sessions);
}

it('loads the promotions once for the three stages of a run', function (): void {
    evPromotion('catalog', PromotionStage::Catalog, [evLinePercent(1000)]);
    evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]);
    evPromotion('ship', PromotionStage::Delivery, [evDeliveryPercent(5000)]);
    $result = null;

    $queries = pdfQueries(function () use (&$result): void {
        $result = evRun(evInput(['1000.00'], delivery: '300.00'));
    });

    expect(evDiscounts($result))->toBe(['line:1 -100.00 catalog', 'order -90.00 cart', 'delivery -150.00 ship'])
        ->and(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"cmz_promotions" as "p"'))))->toHaveCount(1)
        ->and(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"cmz_promotion_rules"'))))->toHaveCount(1);
});

it('sees a changed promotion when the same input runs again', function (): void {
    $promotion = evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]);
    $input = evInput(['1000.00']);

    expect(evDiscounts(evRun($input)))->toBe(['order -100.00 cart']);

    Promotions::promotions()->pause(new PausePromotion($promotion->id));

    expect(evDiscounts(evRun($input)))->toBe([]);
});

it('does not keep the input alive', function (): void {
    evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]);
    $before = evSessionCount();
    $input = evInput(['1000.00']);
    evRun($input);

    expect(evSessionCount())->toBe($before + 1);

    unset($input);
    gc_collect_cycles();

    expect(evSessionCount())->toBe($before);
});

it('starts a session in a later stage when the catalog stage did not run for the input', function (): void {
    evPromotion('cart', PromotionStage::Cart, [pdfPercent(1000)]);
    $input = evInput(['1000.00']);
    $sessions = app(PromotionSessions::class);

    expect($sessions->find($input))->toBeNull()
        ->and($sessions->for($input)->candidates(PromotionStage::Cart))->toHaveCount(1)
        ->and($sessions->find($input))->not->toBeNull();
});
