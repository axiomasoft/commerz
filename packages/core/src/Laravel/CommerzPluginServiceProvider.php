<?php

declare(strict_types=1);

namespace Commerz\Laravel;

use Commerz\Plugins\CorePlugin;
use Commerz\Plugins\Internal\CommerzActivationGate;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;

/** Makes selected Laravel services available to the first consumer of the application’s plugin graph. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
abstract class CommerzPluginServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var class-string<ExtensionPlugin&WithConfiguration> */
    protected const string PLUGIN = CorePlugin::class;

    private ?bool $active = null;

    #[Override]
    public function register(): void
    {
        if ($this->app->getProviders(static::class) !== []) {
            return;
        }

        $this->app->register(PackmanServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->app->register(SchemaServiceProvider::class);
        $this->app->singletonIf(CommerzActivationGate::class);
        ExtensionRegistrar::addActivationGate($this->app, CommerzActivationGate::class);
        ExtensionRegistrar::builtInFor($this->app, static::PLUGIN);

        $this->app->beforeResolving(Contributions::class, $this->activate(...));
        $this->app->booting($this->activate(...));
        $this->booting($this->activate(...));
    }

    private function activate(): void
    {
        if ($this->active === null) {
            $id = (new (static::PLUGIN))->manifest()->id;
            $this->active = ExtensionRegistrar::activationGateFor($this->app)?->isEnabled($id) !== false;
            if ($this->active) {
                $this->registerPackageRegistrars();
            }
        }
    }

    public function boot(): void
    {
        if ($this->active !== true) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $plugin = new (static::PLUGIN);
            $root = $plugin->configRoot();
            $path = $plugin->defaultConfigPath();
            if ($path !== null) {
                $this->publishes([$path => $this->app->configPath($root.'.php')], $root.'-config');
            }
        }

        $this->bootPackageRegistrars();
    }
}
