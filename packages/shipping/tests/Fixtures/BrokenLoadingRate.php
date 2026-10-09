<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Shipping\Rates\Contracts\DeliveryRate;
use Commerz\Shipping\Rates\Data\RateInput;
use Commerz\Shipping\Rates\Data\RateParams;
use Commerz\Shipping\Rates\Data\RatePrice;
use Commerz\Shipping\Rates\Data\RateScope;
use RuntimeException;

/** A plugin that becomes unable to load its stored tariffs after they have been written. */
final class BrokenLoadingRate implements DeliveryRate
{
    public ?string $failOn = null;

    public function key(): string
    {
        return 'test.broken_loading';
    }

    public function version(): int
    {
        $this->fail('version');

        return 2;
    }

    public function normalize(RateParams $params, RateScope $scope): RateParams
    {
        $this->fail('normalize');

        return $params;
    }

    public function upgrade(RateParams $params, int $fromVersion): RateParams
    {
        $this->fail('upgrade');

        return $params;
    }

    public function price(RateInput $input): RatePrice
    {
        return RatePrice::of(Money::ofMinor(100, $input->currency));
    }

    private function fail(string $operation): void
    {
        if ($this->failOn === $operation) {
            throw new RuntimeException("{$operation} failed");
        }
    }
}
