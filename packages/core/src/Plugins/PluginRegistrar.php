<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Quotes\Contracts\QuoteStage;
use LogicException;
use Packman\Attributes\Api;
use Packman\Plugins\Data\Contributions\ContributionKind;

/**
 * Legacy plugin declaration recorder retained in Slice A for unmigrated feature subpackages.
 *
 * @deprecated Use Packman\Plugins\Laravel\PluginRegistrar instead.
 */
#[Api(since: '0.1')]
final class PluginRegistrar
{
    /** @var list<ContributionKind> */
    private array $kinds = [];

    /** @var list<array{kind: string, contribution: object|class-string}> */
    private array $contributions = [];

    private bool $sealed = false;

    /** @internal created by the Slice-A legacy plugin compiler bridge */
    public function __construct(public readonly string $pluginId) {}

    public function declareKind(ContributionKind $kind): void
    {
        if ($kind->ownerPluginId !== $this->pluginId) {
            throw new InvalidConfiguration('plugin.kind.owner', "plugin '{$this->pluginId}' declared kind '{$kind->name}' for '{$kind->ownerPluginId}'");
        }

        $this->guard();
        $this->kinds[] = $kind;
    }

    /** @param object|class-string $contribution */
    public function contribute(string $kind, object|string $contribution): void
    {
        $this->guard();
        $this->contributions[] = ['kind' => $kind, 'contribution' => $contribution];
    }

    /** @param SellableProvider|class-string<SellableProvider> $provider */
    public function sellableProvider(SellableProvider|string $provider): void
    {
        $this->contribute(SellableProvider::KIND, $provider);
    }

    /** @param QuoteStage|class-string<QuoteStage> $stage */
    public function quoteStage(QuoteStage|string $stage): void
    {
        $this->contribute(QuoteStage::KIND, $stage);
    }

    /** @internal */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * @internal
     *
     * @return list<ContributionKind>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /**
     * @internal
     *
     * @return list<array{kind: string, contribution: object|class-string}>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    private function guard(): void
    {
        if ($this->sealed) {
            throw new LogicException("Plugin '{$this->pluginId}' changed the registry after register(); the plugin graph is frozen.");
        }
    }
}
