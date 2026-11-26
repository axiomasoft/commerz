<?php

declare(strict_types=1);

namespace Commerz\B2b\Tests;

use Commerz\B2b\B2bServiceProvider;
use Commerz\CommerzServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, B2bServiceProvider::class];
    }
}
