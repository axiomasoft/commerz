<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Evaluation/Helpers.php';
require_once __DIR__.'/Usage/Helpers.php';

uses(RefreshDatabase::class);

/** A cart of two pairs of shoes (2 × 2000.00) and a shirt (500.00). */
function pscnCart(string ...$codes): QuoteInput
{
    return evInput([['2x2000.00', 'categories' => ['shoes']], '500.00'], array_values($codes));
}

/**
 * What each promotion took off the quote, as `<promotion code>|<coupon>|<discount>`.
 *
 * @return list<string>
 */
function pscnApplied(QuoteResult $quote): array
{
    $codes = DB::table('cmz_promotions')->pluck('code', 'id')->all();

    return array_map(
        static fn ($a): string => $codes[$a->promotionId].'|'.($a->couponCode ?? '-').'|'.$a->discount->toDecimal(),
        $quote->appliedPromotions(),
    );
}

it('takes a coupon from the quote through the placement transaction to its limit and back', function (): void {
    $shoes = evPromotion('shoes-10', PromotionStage::Catalog, [evLinePercent(1000)], [
        'conditions' => [new RuleSpec('line.category_in', ['categories' => ['shoes']])],
    ]);
    $autumn = cpnPromotion('autumn', [
        'stage' => PromotionStage::Cart,
        'conditions' => [pdfMinimum('3000.00')],
        'effects' => [pdfPercent(1000)],
        'limits' => new UsageLimits(total: 1),
        'budget' => Money::of('1000.00', 'RUB'),
    ]);
    cpnCoupon($autumn, 'AUTUMN');

    // The quote consumes nothing: the code is typed in sloppily and still matches (PRM-03).
    $quote = evRun(pscnCart(' autumn '));

    expect($quote->total->toDecimal())->toBe('3690.00')
        ->and(evDiscounts($quote))->toBe(['line:1 -400.00 shoes-10', 'order -410.00 autumn AUTUMN'])
        ->and(pscnApplied($quote))->toBe(['shoes-10|-|400.00', 'autumn|AUTUMN|410.00'])
        ->and(evRejections($quote))->toBe([])
        ->and(usgPromotionCounters($autumn))->toBe(['used' => 0, 'spent' => 0]);

    // explain gives a verdict per promotion that was looked at.
    expect(evVerdicts(pscnCart('AUTUMN')))->toBe(['shoes-10|applied|400.00', 'autumn|applied|410.00']);

    // Placement: the sales transaction consumes the applied promotions of the quote (D13, D25).
    app(Transactions::class)->run(fn () => app(PromotionUsage::class)->consume(
        new ConsumePromotionUsage('order:1', null, $quote->appliedPromotions()),
    ));

    // Only the limited promotion counts: the unlimited catalog promotion stays a cold row (D27).
    expect(usgPromotionCounters($autumn))->toBe(['used' => 1, 'spent' => 41_000])
        ->and(usgPromotionCounters($shoes))->toBe(['used' => 0, 'spent' => 0]);

    // The next quote with the same code is told the promotion is used up; the catalog promotion still applies.
    $next = evRun(pscnCart('AUTUMN'));

    expect($next->total->toDecimal())->toBe('4100.00')
        ->and(evRejections($next))->toBe(['promotions.cart|usage_limit_reached|AUTUMN'])
        ->and(evVerdicts(pscnCart('AUTUMN')))->toBe(['shoes-10|applied|400.00', 'autumn|rejected|usage_limit_reached']);

    // A racing order that was quoted before is refused at placement and rolls its whole transaction back.
    $refused = null;

    try {
        app(Transactions::class)->run(function () use ($quote): void {
            app(PromotionUsage::class)->consume(new ConsumePromotionUsage('order:2', null, $quote->appliedPromotions()));
        });
    } catch (UsageLimitReached $e) {
        $refused = $e;
    }

    expect($refused?->limit)->toBe('total')
        ->and($refused?->code())->toBe('promotions.usage_limit_reached')
        ->and(DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:2')->exists())->toBeFalse()
        ->and(usgPromotionCounters($autumn))->toBe(['used' => 1, 'spent' => 41_000]);

    // Cancelling the first order gives the quota and the budget back; the second order now fits.
    app(PromotionUsage::class)->restore(new RestorePromotionUsage('order:1', RestoreReason::Cancelled));

    expect(usgPromotionCounters($autumn))->toBe(['used' => 0, 'spent' => 0])
        ->and(DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:1')->value('state'))->toBe('restored');

    app(Transactions::class)->run(fn () => app(PromotionUsage::class)->consume(
        new ConsumePromotionUsage('order:2', null, $quote->appliedPromotions()),
    ));

    expect(usgPromotionCounters($autumn))->toBe(['used' => 1, 'spent' => 41_000])
        ->and(DB::table('cmz_promotion_redemptions')->where('order_ref', 'order:2')->value('state'))->toBe('consumed');

    // The counters equal the usage log (PRM-01).
    $this->artisan('commerz:promotions:reconcile')->expectsOutputToContain('Promotions are consistent.')->assertExitCode(0);
});
