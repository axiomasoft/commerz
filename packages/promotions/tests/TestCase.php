<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PromotionsServiceProvider::class];
    }
}
