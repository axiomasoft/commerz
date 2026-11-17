<?php

declare(strict_types=1);

namespace Commerz\Plugins\Internal;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;

/**
 * Tracks built-in Commerz extension plugins registered prior to container plugin graph compilation.
 */
#[Internal]
final class BuiltInPluginCatalog
{
    /** @var list<class-string<ExtensionPlugin>> */
    private array $builtIn = [];

    private bool $sealed = false;

    /** @param class-string<ExtensionPlugin> $plugin */
    public function add(string $plugin): void
    {
        if ($this->sealed) {
            throw new LogicException('The plugin graph is already compiled; register built-in plugins before resolving Contributions.');
        }

        if (! in_array($plugin, $this->builtIn, true)) {
            $this->builtIn[] = $plugin;
        }
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @return list<class-string<ExtensionPlugin>> */
    public function classes(): array
    {
        return $this->builtIn;
    }
}
