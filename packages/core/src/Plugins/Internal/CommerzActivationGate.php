<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Configuration\CommerzOptions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;

/** Applies the product's disabled list even when Packman prepares options before graph compilation. */
#[Internal]
final readonly class CommerzActivationGate implements PluginActivationGate
{
    private bool $hostOptions;

    public function __construct(private Application $app)
    {
        // Capture a host binding before Packman registers its default lazy options factory.
        $this->hostOptions = $app->bound(CommerzOptions::class);
    }

    #[Override]
    public function isEnabled(string $pluginId): bool
    {
        $config = $this->app->make(Repository::class);
        $disabled = $config->get('packman.disabled_plugins', []);
        if (is_array($disabled) && in_array($pluginId, $disabled, true)) {
            return false;
        }

        $local = $this->hostOptions || $this->app->resolved(CommerzOptions::class)
            ? $this->app->make(CommerzOptions::class)->disabledPlugins
            : $config->get('commerz.disabled_plugins', []);

        return ! is_array($local) || ! in_array($pluginId, $local, true);
    }
}
