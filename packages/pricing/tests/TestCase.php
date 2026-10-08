<?php

declare(strict_types=1);

namespace Commerz\Pricing\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PricingServiceProvider::class];
    }
}
