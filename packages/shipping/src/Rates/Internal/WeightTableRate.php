<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Internal;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;

/**
 * A price per weight step: `steps` is a list of `{up_to_grams, amount}`, ascending; the first step the parcel fits
 * (its weight is at most `up_to_grams`, the bound included) sets the price. The last step may be open (`up_to_grams`
 * null). Without an open step the last finite bound is the limit of the method and a heavier parcel is not delivered.
 */
final readonly class WeightTableRate implements DeliveryRate
{
    private const int MAX_STEPS = 100;

    private const int MAX_GRAMS = 10_000_000;

    public function key(): string
    {
        return 'weight_table';
    }

    public function version(): int
    {
        return 1;
    }

    public function normalize(RateParams $params, RateScope $scope): RateParams
    {
        $params->only(['steps']);

        $steps = [];

        foreach (self::steps($params, $scope->currency) as [$upTo, $amount]) {
            $steps[] = ['up_to_grams' => $upTo, 'amount' => $amount];
        }

        return RateParams::fromInput(['steps' => $steps]);
    }

    public function upgrade(RateParams $params, int $fromVersion): RateParams
    {
        throw new InvalidRateConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}: no older versions");
    }

    public function price(RateInput $input): RatePrice
    {
        $weight = $input->request->parcel->weightGrams;

        foreach (self::steps($input->params, $input->currency) as [$upTo, $amount]) {
            if ($upTo === null || $weight <= $upTo) {
                return RatePrice::of($amount);
            }
        }

        return RatePrice::unavailable('weight_out_of_range', "The parcel weighs {$weight} g, more than the method carries.");
    }

    /**
     * @return list<array{int|null, Money}> the validated steps in order
     *
     * @throws InvalidRateConfig
     */
    private static function steps(RateParams $params, Currency $currency): array
    {
        $items = $params->list('steps', 1, self::MAX_STEPS);
        $last = count($items) - 1;
        $steps = [];
        $previous = 0;

        foreach ($items as $index => $item) {
            $path = "steps.{$index}";
            $keys = array_keys($item);
            sort($keys);

            if ($keys !== ['amount', 'up_to_grams']) {
                throw new InvalidRateConfig($path, 'expected exactly up_to_grams and amount');
            }

            $step = RateParams::fromInput($item);

            try {
                $upTo = $step->nullableInt('up_to_grams', 1, self::MAX_GRAMS);
                $amount = $step->money('amount', $currency);
            } catch (InvalidRateConfig $e) {
                throw new InvalidRateConfig("{$path}.{$e->field}", $e->reason);
            }

            if ($upTo === null && $index !== $last) {
                throw new InvalidRateConfig("{$path}.up_to_grams", 'only the last step may be open (null)');
            }

            if ($upTo !== null && $upTo <= $previous) {
                throw new InvalidRateConfig("{$path}.up_to_grams", 'must be greater than the previous step');
            }

            if ($amount->isNegative()) {
                throw new InvalidRateConfig("{$path}.amount", 'must not be negative');
            }

            $previous = $upTo ?? $previous;
            $steps[] = [$upTo, $amount];
        }

        return $steps;
    }
}
