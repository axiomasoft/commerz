<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;

/** At version 2: version 1 kept the amount in `cost`. */
final class FixedRate implements DeliveryRate
{
    public function key(): string
    {
        return 'test.fixed';
    }

    public function version(): int
    {
        return 2;
    }

    public function normalize(RateParams $params, RateScope $scope): RateParams
    {
        $params->only(['amount']);
        $amount = $params->money('amount', $scope->currency);

        if ($amount->isNegative()) {
            throw new InvalidRateConfig('amount', 'must not be negative');
        }

        return RateParams::fromInput(['amount' => $amount]);
    }

    public function upgrade(RateParams $params, int $fromVersion): RateParams
    {
        return RateParams::fromInput(['amount' => $params->toArray()['cost'] ?? null]);
    }

    public function price(RateInput $input): RatePrice
    {
        return RatePrice::of($input->params->money('amount', $input->currency));
    }
}
