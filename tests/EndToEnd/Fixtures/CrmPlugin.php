<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/** The CRM host's plugin: it sells its own services next to (or instead of) the catalog's variants. */
final class CrmPlugin implements ExtensionPlugin
{
    public const string ID = 'tests.crm';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '1.0.0', [CorePlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(SellableProvider::KIND, CrmServices::class);
    }
}
