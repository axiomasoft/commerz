<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Subscriptions\SubscriptionsServiceProvider;
use Commerz\Testkit\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SubscriptionsServiceProvider::class];
    }
}
