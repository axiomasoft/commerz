<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests\Feature;

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\Catalog\Plugins\CatalogPlugin;
use Commerz\CommerzServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Commerz\Testkit\PluginContractTests;

final class CatalogPluginTest extends PackageTestCase
{
    use PluginContractTests;

    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, CatalogServiceProvider::class];
    }

    protected function pluginClass(): string
    {
        return CatalogPlugin::class;
    }

    /** @return array<string, list<string>> */
    protected function expectedContributions(): array
    {
        return [
            'attribute_type' => [
                'text', 'localized_text', 'integer', 'decimal', 'boolean', 'date', 'enum', 'multi_enum', 'measure', 'reference',
            ],
            'outbox_consumer' => ['catalog.storefront_projection'],
            'product_kind' => ['physical', 'digital', 'service'],
            'sellable_provider' => ['variant'],
        ];
    }
}
