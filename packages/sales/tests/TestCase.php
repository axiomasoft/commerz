<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SalesServiceProvider::class];
    }
}
