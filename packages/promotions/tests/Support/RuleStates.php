<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Support;

use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Data\ConditionInput;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

/** Builds the quote states and rule inputs the built-in rules are tested on: RUB, a fixed moment, fixed line ids. */
final class RuleStates
{
    public static function currency(): Currency
    {
        return Currency::of('RUB');
    }

    public static function scope(PromotionStage $stage = PromotionStage::Cart, ?string $currency = 'RUB'): RuleScope
    {
        return new RuleScope($stage, $currency === null ? null : Currency::of($currency));
    }

    public static function summary(PromotionStage $stage = PromotionStage::Cart): PromotionSummary
    {
        return new PromotionSummary(PromotionId::generate(), 'test', 'Test', $stage, 'automatic', 100, self::currency());
    }

    /**
     * @param  list<array{id: string, unit: string, qty?: int, ref?: string, categories?: list<string>}>  $lines
     * @param  list<string>  $groups
     */
    public static function state(array $lines, array $groups = [], ?string $delivery = null): QuoteState
    {
        $context = CommerceContext::for(ChannelId::generate(), self::currency(), at: new DateTimeImmutable('2026-10-09T12:00:00Z'));

        if ($groups !== []) {
            $context = $context->withCustomer(CustomerRef::of('customer', 'c1'), $groups);
        }

        $snapshots = [];
        $inputs = [];

        foreach ($lines as $line) {
            $snapshots[$line['id']] = new SellableSnapshot(
                SellableRef::fromString($line['ref'] ?? 'variant:'.$line['id']),
                'SKU-'.$line['id'],
                'Item '.$line['id'],
                SellableKind::Physical,
                true,
                categories: $line['categories'] ?? [],
            );
            $inputs[] = new QuoteLineInput($line['id'], $snapshots[$line['id']], $line['qty'] ?? 1);
        }

        $state = QuoteState::start(new QuoteInput($context, $inputs));

        foreach ($lines as $line) {
            $state = $state->withLine(new QuoteLine(
                $line['id'],
                $snapshots[$line['id']],
                $line['qty'] ?? 1,
                Money::of($line['unit'], 'RUB'),
                null,
                false,
                'test',
            ));
        }

        return $delivery === null ? $state : $state->withDelivery(new DeliveryCharge('post', 'Post', 'carrier', Money::of($delivery, 'RUB')));
    }

    /**
     * @param  list<string>|null  $eligible  default: every line
     * @param  array<string, mixed>  $params
     */
    public static function condition(QuoteState $state, array $params, ?array $eligible = null): ConditionInput
    {
        return new ConditionInput(
            $state,
            self::summary(),
            RuleParams::fromInput($params),
            $eligible ?? array_map(static fn (QuoteLine $line): string => $line->lineId, $state->lines),
        );
    }

    /**
     * @param  list<string>|null  $eligible  default: every line
     * @param  array<string, mixed>  $params
     */
    public static function effect(QuoteState $state, array $params, ?array $eligible = null, RoundingMode $rounding = RoundingMode::HalfUp): EffectInput
    {
        return new EffectInput(
            $state,
            self::summary(),
            RuleParams::fromInput($params),
            $eligible ?? array_map(static fn (QuoteLine $line): string => $line->lineId, $state->lines),
            $rounding,
        );
    }
}
