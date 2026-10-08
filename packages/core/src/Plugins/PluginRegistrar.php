<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidConfiguration;
use Commerz\Plugins\Internal\PluginDeclarations;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use LogicException;

/**
 * Handed to `CommerzPlugin::register()`. It only records declarations; the compiler validates them against the whole
 * graph. A registrar is sealed when `register()` returns, so a late call fails instead of mutating a frozen registry.
 */
#[Api(since: '0.1')]
final readonly class PluginRegistrar
{
    /** @internal created by the plugin compiler */
    public function __construct(private PluginDeclarations $declarations, public string $pluginId) {}

    /** Declares a contribution kind this plugin owns. */
    public function declareKind(ContributionKind $kind): void
    {
        if ($kind->ownerPluginId !== $this->pluginId) {
            throw new InvalidConfiguration('plugin.kind.owner', "plugin '{$this->pluginId}' declared kind '{$kind->name}' for '{$kind->ownerPluginId}'");
        }

        $this->guard();
        $this->declarations->addKind($kind);
    }

    /**
     * Adds a contribution to a kind. The key comes from its `key()` method. A class name is resolved through the
     * container when the graph is compiled.
     *
     * @param  object|class-string  $contribution
     */
    public function contribute(string $kind, object|string $contribution): void
    {
        $this->guard();
        $this->declarations->addContribution($kind, $contribution);
    }

    /**
     * Serves sellables of the ref type equal to the provider's key.
     *
     * @param  SellableProvider|class-string<SellableProvider>  $provider
     */
    public function sellableProvider(SellableProvider|string $provider): void
    {
        $this->contribute(SellableProvider::KIND, $provider);
    }

    /**
     * Adds a stage to the quote pipeline.
     *
     * @param  QuoteStage|class-string<QuoteStage>  $stage
     */
    public function quoteStage(QuoteStage|string $stage): void
    {
        $this->contribute(QuoteStage::KIND, $stage);
    }

    private function guard(): void
    {
        if ($this->declarations->sealed()) {
            throw new LogicException("Plugin '{$this->pluginId}' changed the registry after register(); the plugin graph is frozen.");
        }
    }
}
