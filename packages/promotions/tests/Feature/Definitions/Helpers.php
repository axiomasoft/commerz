<?php

declare(strict_types=1);

// Shared fixtures of the promotion definition tests.

use Commerz\Errors\CommerzException;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Campaigns\Data\CampaignId;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\StoreId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/Rows.php';

function pdfChannel(string $code = 'web', string $currency = 'RUB'): ChannelId
{
    $existing = DB::table('cmz_channels')->where('code', $code)->value('id');

    if (is_string($existing)) {
        return new ChannelId($existing);
    }

    $store = DB::table('cmz_stores')->where('code', 'main')->value('id');

    if (! is_string($store)) {
        if ($store !== null) {
            throw new RuntimeException('Expected a store id, got '.get_debug_type($store).'.');
        }

        $store = app(Stores::class)->create(new CreateStore('main', 'Main', Currency::of('RUB')))->id->value;
    }

    return app(Channels::class)->create(new CreateChannel(new StoreId($store), $code, ucfirst($code), [Currency::of($currency)]))->id;
}

function pdfPercent(int $rateBp = 1000): RuleSpec
{
    return new RuleSpec('order.percent_off', ['rateBp' => $rateBp]);
}

function pdfMinimum(string $amount = '3000.00', string $currency = 'RUB'): RuleSpec
{
    return new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of($amount, $currency)]);
}

/** @param  array<string, mixed>  $with  named arguments of the command that replace the defaults */
function pdfCommand(string $code = 'autumn', array $with = []): CreatePromotion
{
    $arguments = [
        'code' => $code,
        'name' => ucfirst($code),
        'stage' => PromotionStage::Cart,
        'activator' => 'automatic',
        'channels' => [pdfChannel()],
        'effects' => [pdfPercent()],
        ...$with,
    ];

    /** @var array{code: string, name: string, stage: PromotionStage, activator: string, channels: list<ChannelId>, effects: list<RuleSpec>, conditions?: list<RuleSpec>, stacking?: Stacking, stopFurther?: bool, priority?: int, currency?: Currency|null, limits?: UsageLimits, budget?: Money|null, startsAt?: DateTimeImmutable|null, endsAt?: DateTimeImmutable|null, status?: PromotionStatus, campaignId?: CampaignId|null} $arguments */
    return new CreatePromotion(...$arguments);
}

/** @param  array<string, mixed>  $with */
function pdfPromotion(string $code = 'autumn', array $with = []): PromotionView
{
    return Promotions::promotions()->create(pdfCommand($code, $with));
}

/**
 * The error code (and field, when it has one) a call fails with.
 *
 * @param  Closure(): mixed  $call
 */
function pdfFailure(Closure $call): string
{
    try {
        $call();
    } catch (CommerzException $e) {
        $field = property_exists($e, 'field') ? $e->field : null;

        return $e->code().(is_string($field) ? " @ {$field}" : '');
    }

    return 'no error';
}

function pdfAt(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
}

/**
 * @param  Closure(): mixed  $call
 * @return list<string> the SQL the call sends to the database
 */
function pdfQueries(Closure $call): array
{
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $call();

    return $queries;
}
