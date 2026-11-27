<?php

declare(strict_types=1);

namespace Commerz\B2b\Tests\Feature;

use Commerz\B2b\B2bServiceProvider;
use Commerz\B2b\Plugins\B2bPlugin;
use Commerz\CommerzServiceProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;

final class B2bPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, B2bServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return B2bPlugin::class;
    }

    /** @return array<string, list<string>> */
    protected function expectedContributions(): array
    {
        return [
            QuoteStage::KIND => ['b2b.locked_prices'],
        ];
    }
}
