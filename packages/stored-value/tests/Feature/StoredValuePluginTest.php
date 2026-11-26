<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\StoredValue\Plugins\StoredValuePlugin;
use Commerz\StoredValue\StoredValueServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;

final class StoredValuePluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, StoredValueServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return StoredValuePlugin::class;
    }
}
