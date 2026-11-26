<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\Subscriptions\Plugins\SubscriptionsPlugin;
use Commerz\Subscriptions\SubscriptionsServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;

final class SubscriptionsPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SubscriptionsServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return SubscriptionsPlugin::class;
    }
}
