<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests;

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\CommerzServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, CatalogServiceProvider::class];
    }
}
