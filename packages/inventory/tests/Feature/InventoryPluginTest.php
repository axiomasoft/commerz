<?php

declare(strict_types=1);

namespace Commerz\Inventory\Tests\Feature;

use Commerz\CommerzServiceProvider;
use Commerz\Inventory\Internal\InventoryPlugin;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;

final class InventoryPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, InventoryServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return InventoryPlugin::class;
    }

    /** @return array<string, list<string>> */
    protected function expectedContributions(): array
    {
        return ['allocation_strategy' => ['priority', 'single_location']];
    }
}
