<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Internal;

use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Packman\Attributes\Internal;

/** The same amount for every parcel: `{amount}` in the currency of the tariff. */
#[Internal]
final readonly class FlatRate implements DeliveryRate
{
    public function key(): string
    {
        return 'flat';
    }

    public function version(): int
    {
        return 1;
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
        throw new InvalidRateConfig('version', "cannot upgrade {$this->key()} from version {$fromVersion}: no older versions");
    }

    public function price(RateInput $input): RatePrice
    {
        return RatePrice::of($input->params->money('amount', $input->currency));
    }
}
