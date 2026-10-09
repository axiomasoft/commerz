<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;

/** Prices every parcel at 10.00 USD, whatever the currency of its tariff. */
final class ForeignCurrencyRate implements DeliveryRate
{
    public function key(): string
    {
        return 'test.foreign_currency';
    }

    public function version(): int
    {
        return 1;
    }

    public function normalize(RateParams $params, RateScope $scope): RateParams
    {
        return $params;
    }

    public function upgrade(RateParams $params, int $fromVersion): RateParams
    {
        return $params;
    }

    public function price(RateInput $input): RatePrice
    {
        return RatePrice::of(Money::of('10.00', 'USD'));
    }
}
