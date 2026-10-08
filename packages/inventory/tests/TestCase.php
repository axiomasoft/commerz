<?php

declare(strict_types=1);

namespace Commerz\Inventory\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, InventoryServiceProvider::class];
    }
}
