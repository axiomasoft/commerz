<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\StoredValue\StoredValueServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, StoredValueServiceProvider::class];
    }
}
