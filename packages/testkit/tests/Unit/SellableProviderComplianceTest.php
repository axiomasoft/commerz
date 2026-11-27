<?php

declare(strict_types=1);

namespace Commerz\Testkit\Tests\Unit;

use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Testkit\Contracts\AssertsSellableProviderCompliance;
use Commerz\Testkit\Fakes\FakeSellables;
use PHPUnit\Framework\TestCase;

final class SellableProviderComplianceTest extends TestCase
{
    use AssertsSellableProviderCompliance;

    private ?FakeSellables $provider = null;

    protected function sellableProvider(): SellableProvider
    {
        return $this->provider ??= new FakeSellables('fake')->add(
            FakeSellables::snapshot('fake:item-1', Money::ofMinor(1995, Currency::of('USD'))),
        );
    }

    /** @return list<SellableRef> */
    protected function knownSellableRefs(): array
    {
        return [SellableRef::of('fake', 'item-1')];
    }
}
