<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;

/** At version 3, and unable to read what version 1 stored. */
final class NewerRate implements DeliveryRate
{
    public function key(): string
    {
        return 'test.newer';
    }

    public function version(): int
    {
        return 3;
    }

    public function normalize(RateParams $params, RateScope $scope): RateParams
    {
        return $params;
    }

    public function upgrade(RateParams $params, int $fromVersion): RateParams
    {
        throw new InvalidRateConfig('legacy', 'the old format is gone');
    }

    public function price(RateInput $input): RatePrice
    {
        return RatePrice::unavailable('not_priced', 'Fixture.');
    }
}
