<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use Commerz\Errors\UnsupportedContribution;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginDescription;

/** The compiled registry. Built once by PluginGraphCompiler; nothing can be added afterwards. */
final readonly class FrozenContributions implements Contributions, PluginInspector
{
    /**
     * @param  array<string, ContributionKind>  $kinds
     * @param  array<string, array<string, object>>  $items  kind => key => contribution, in load order
     * @param  list<PluginDescription>  $plugins
     */
    public function __construct(private array $kinds, private array $items, private array $plugins) {}

    public function get(string $kind, string $key, string $interface): object
    {
        if (! isset($this->kinds[$kind])) {
            throw new UnsupportedContribution($kind, $key, 'no installed plugin declares this contribution kind');
        }

        $contribution = $this->items[$kind][$key] ?? throw new UnsupportedContribution($kind, $key, 'no registered plugin contributes this key');

        if (! $contribution instanceof $interface) {
            throw new UnsupportedContribution($kind, $key, "the contribution does not implement {$interface}");
        }

        return $contribution;
    }

    public function has(string $kind, string $key): bool
    {
        return isset($this->items[$kind][$key]);
    }

    public function keys(string $kind): array
    {
        return array_keys($this->items[$kind] ?? []);
    }

    public function describe(): array
    {
        return $this->plugins;
    }
}
