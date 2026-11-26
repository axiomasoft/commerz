<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Testkit\Contracts\AssertsPickupPointCompliance;
use Commerz\Testkit\Fakes\FakePickupPoints;
use PHPUnit\Framework\TestCase;

final class PickupPointComplianceTest extends TestCase
{
    use AssertsPickupPointCompliance;

    private ?FakePickupPoints $provider = null;

    protected function pickupPointProvider(): PickupPointProvider
    {
        return $this->provider ??= (new FakePickupPoints('fake'))->add(
            new PickupPoint(
                id: 'pudo-sf-1',
                provider: 'fake',
                name: 'SF Locker 1',
                mode: DeliveryMode::PickupPoint,
                address: new Address('US', 'US-CA', 'San Francisco', '94107', ['100 Market St']),
                limits: new PickupPointLimits(maxWeightGrams: 5000, maxLengthMm: 500, maxWidthMm: 400, maxHeightMm: 300),
                isLocker: true,
            ),
        );
    }

    /** @return list<string> */
    protected function knownPickupPointIds(): array
    {
        return ['pudo-sf-1'];
    }
}
