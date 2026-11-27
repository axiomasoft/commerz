<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Data\QuoteLine;
use RuntimeException;

/** An effect that answers what the engine must not trust, chosen by `mode`. */
final class RogueEffect implements PromotionEffect
{
    public const array MODES = ['every_line', 'foreign_currency', 'order', 'negative', 'not_a_discount', 'throws'];

    public function key(): string
    {
        return 'test.rogue_effect';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Catalog, PromotionStage::Cart];
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        return RuleParams::fromInput(['mode' => $params->only(['mode'])->choice('mode', self::MODES)]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        return $params;
    }

    public function apply(EffectInput $input): array
    {
        $one = Money::of('1.00', $input->state->currency());

        return match ($input->params->string('mode')) {
            // every line of the quote, not only the eligible ones
            'every_line' => array_map(static fn (QuoteLine $line): Discount => Discount::line($line->lineId, $one), $input->state->lines),
            'foreign_currency' => [Discount::order(Money::of('1.00', 'USD'))],
            'order' => [Discount::order($one)],
            'negative' => [Discount::line($input->lines[0], $one->negate())],
            'not_a_discount' => self::notADiscount($one),
            default => throw new RuntimeException('the effect broke'),
        };
    }

    /**
     * The engine must reject a contributor that returns a value which is not a Discount.
     * The boxed list still contains the money at runtime.
     *
     * @return list<Discount>
     */
    private static function notADiscount(Money $money): array
    {
        $rows = self::boxed($money);

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('an effect must return a list');
        }

        /** @var list<Discount> $rows */
        return $rows;
    }

    private static function boxed(Money $money): mixed
    {
        return [$money];
    }
}
