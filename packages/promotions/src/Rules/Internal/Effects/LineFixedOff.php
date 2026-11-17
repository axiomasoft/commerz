<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\Discount;
use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Packman\Attributes\Internal;

/** A fixed amount off every eligible line, per unit or per line, never more than the line's current net. */
#[Internal]
final readonly class LineFixedOff implements PromotionEffect
{
    public function key(): string
    {
        return 'line.fixed_off';
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
        $params->only(['amount', 'per']);
        $amount = $params->money('amount', $scope->currencyFor('amount'));

        if (! $amount->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        return RuleParams::fromInput([
            'amount' => $amount,
            'per' => $params->choice('per', ['unit', 'line'], 'unit'),
        ]);
    }

    public function upgrade(RuleParams $params, int $fromVersion): RuleParams
    {
        throw new InvalidRuleConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}");
    }

    public function apply(EffectInput $input): array
    {
        $amount = $input->params->money('amount', $input->state->currency());

        if (! $amount->isPositive()) {
            throw new InvalidRuleConfig('amount', 'must be greater than zero');
        }

        $perUnit = $input->params->choice('per', ['unit', 'line'], 'unit') === 'unit';
        $discounts = [];

        foreach ($input->lines as $lineId) {
            $line = $input->state->line($lineId)
                ?? throw new InvalidCommand('lineId', "'{$lineId}' is not a priced line", 'quotes.unknown_line');
            $net = $input->state->lineNet($lineId);
            $discount = $perUnit ? self::perUnit($amount, $net, $line->quantity) : self::atMost($amount, $net);

            if ($discount->isPositive()) {
                $discounts[] = Discount::line($lineId, $discount);
            }
        }

        return $discounts;
    }

    /** `min(amount × quantity, net)` without the product: it reaches the net exactly when the amount reaches the net per unit, rounded up. */
    private static function perUnit(Money $amount, Money $net, int $quantity): Money
    {
        $unitCeiling = intdiv($net->amountMinor, $quantity) + ($net->amountMinor % $quantity > 0 ? 1 : 0);

        return $amount->amountMinor >= $unitCeiling ? $net : $amount->multiply($quantity);
    }

    private static function atMost(Money $amount, Money $net): Money
    {
        return $amount->compare($net) > 0 ? $net : $amount;
    }
}
