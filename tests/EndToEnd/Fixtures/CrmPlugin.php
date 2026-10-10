<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Fixtures;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

/** The CRM host's plugin: it sells its own services next to (or instead of) the catalog's variants. */
final class CrmPlugin implements CommerzPlugin
{
    public const string ID = 'tests.crm';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->sellableProvider(CrmServices::class);
    }
}
