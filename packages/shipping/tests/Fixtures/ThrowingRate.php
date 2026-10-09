<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use RuntimeException;

/** Accepts any parameters and fails every time it is asked for a price. */
final class ThrowingRate implements DeliveryRate
{
    public function key(): string
    {
        return 'test.throwing';
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
        throw new RuntimeException('the rate service is down');
    }
}
