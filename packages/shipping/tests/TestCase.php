<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Shipping\ShippingServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, ShippingServiceProvider::class];
    }
}
