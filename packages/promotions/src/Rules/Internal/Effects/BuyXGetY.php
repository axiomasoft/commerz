<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Packman\Attributes\Internal;

/**
 * Of every `buy + get` units of the eligible lines, the `get` cheapest are discounted by `rateBp` (10000 makes them
 * free), at most `maxApplications` times. One pool across the lines, as commercetools' multibuy with the cheapest
 * selection.
 */
#[Internal]
final readonly class BuyXGetY implements PromotionEffect
{
    public function key(): string
    {
        return 'buy_x_get_y';
    }

    public function version(): int
    {
        return 1;
    }

    public function stages(): array
    {
        return [PromotionStage::Cart];
    }

    public function normalize(RuleParams $params, RuleScope $scope): RuleParams
    {
        $params->only(['buy', 'get', 'rateBp', 'maxApplications']);

        return RuleParams::fromInput([
            'buy' => $params->int('buy', 1, 1000),
            'get' => $params->int('get', 1, 1000),
            'maxApplications' => $params->nullableInt('maxApplications', 1),
            'rateBp' => array_key_exists('rateBp', $params->toArray()) ? $params->int('rateBp', 1, 10_000) : 10_000,
        ]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function apply(EffectInput $input): array
    {
        $buy = $input->params->int('buy', 1, 1000);
        $get = $input->params->int('get', 1, 1000);
        $rate = Rate::bp($input->params->int('rateBp', 1, 10_000));
        $maxApplications = $input->params->nullableInt('maxApplications', 1);

        $nets = [];

        foreach ($input->lines as $lineId) {
            $line = $input->state->line($lineId)
                ?? throw new InvalidCommand('lineId', "'{$lineId}' is not a priced line", 'quotes.unknown_line');
            // A line pushed below zero by earlier discounts still counts its units, all of them free.
            $nets[] = [max(0, $input->state->lineNet($lineId)->amountMinor), $line->quantity];
        }

        $pool = UnitPool::of($nets);
        $applications = intdiv($pool->units, $buy + $get);

        if ($maxApplications !== null) {
            $applications = min($applications, $maxApplications);
        }

        if ($applications === 0) {
            return [];
        }

        $discounts = [];
        $currency = $input->state->currency();

        $selection = $pool->cheapest($applications * $get);
        ksort($selection);

        foreach ($selection as $position => $selected) {
            $amount = Money::ofMinor($selected, $currency)->percentage($rate, $input->rounding);

            if ($amount->isPositive()) {
                $discounts[] = Discount::line($input->lines[$position], $amount);
            }
        }

        return $discounts;
    }
}
